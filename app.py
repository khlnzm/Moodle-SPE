from typing import List, Optional, Tuple
import os
import re
import uvicorn
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel
from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer

# --- App & model ---
app = FastAPI(title="SPE Sentiment API", version="0.8.0")
analyzer = SentimentIntensityAnalyzer()

# =====================================================================
# 1) Lexicon & phrase-level penalties (polite academic negatives)
# =====================================================================

# Single-token weights (on ~[-4..+4] VADER scale)
CUSTOM_WEAK_NEG = {
    "concern": -2.6, "concerns": -2.6,
    "issue": -2.6, "issues": -2.6,
    "problem": -2.9, "problems": -2.9,
    "challenge": -2.6, "challenges": -2.6,
    "difficult": -2.1, "difficulty": -2.1, "difficulties": -2.1,
    "delay": -2.5, "delayed": -2.5, "late": -2.5,
    "inconsistent": -2.6, "inconsistency": -2.6,
    "struggle": -2.7, "struggles": -2.7, "struggling": -2.7,
    "unreliable": -3.0, "unresponsive": -3.0,
    "lack": -2.4, "lacking": -2.4, "insufficient": -2.6,
    "inflexible": -2.8,
    "dominating": -2.8,  # e.g., dominating discussion
    "dominant": -2.6,

    # improvement words are mildly negative individually
    "needs": -1.2,
    "improvement": -1.2, "improve": -1.2, "improving": -1.0,

    "blocking": -2.9, "obstructive": -3.2,
    "conflict": -2.9, "frustrating": -3.0, "frustration": -3.0,
}
analyzer.lexicon.update(CUSTOM_WEAK_NEG)

# Multi-word phrases (replace with single tokens, then weight)
PHRASE_PATTERNS = {
    # Already present / earlier cases
    r"\b(can\s+)?create\s+challenges\b": "create_challenges",
    r"\b(could\s+)?create\s+challenges\b": "create_challenges",
    r"\b(dominate|dominates|dominating)\s+discussions?\b": "dominate_discussions",
    r"\brush\s+through\s+tasks?\b": "rush_through_tasks",
    r"\bminor\s+misunderstandings?\b": "minor_misunderstandings",
    r"\b(in)?consistenc(y|ies)\b": "inconsistencies",
    r"\bstrong\s+opinions\b": "strong_opinions",
    r"\b(in)?flexible\b": "inflexible",
    r"\btime\s+management\s+could\s+improve\b": "time_mgmt_could_improve",
    r"\bdelays?\s+in\s+completing\b": "delays_in_completing",
    r"\baffect(s|ed)?\s+overall\s+progress\b": "affects_overall_progress",
    r"\bneeds?\s+improvement\b": "needs_improvement",
    r"\b(in\s+)need\s+of\s+improvement\b": "needs_improvement",
    r"\broom\s+for\s+improvement\b": "room_for_improvement",

    # NEW soft-downweight phrases to bias "quietly positive" toward neutral
    r"\bnot\s+always\s+take\s+a\s+leading\s+role\b": "not_leading_role",
    r"\b(quiet|understated)\b": "quiet_understated",
    r"\b(neutral|balanced|steady|dependable)\s+member\b": "neutral_member",
}
PHRASE_LEXICON = {
    "create_challenges": -3.1,
    "dominate_discussions": -3.0,
    "rush_through_tasks": -2.7,
    "minor_misunderstandings": -1.8,
    "inconsistencies": -2.4,
    "strong_opinions": -1.4,  # mild on its own
    "inflexible": -3.0,
    "time_mgmt_could_improve": -2.6,
    "delays_in_completing": -2.8,
    "affects_overall_progress": -2.6,
    "needs_improvement": -2.9,
    "room_for_improvement": -2.2,

    # NEW mild negatives to pull borderline positives toward neutral
    "not_leading_role": -0.8,
    "quiet_understated": -0.6,
    "neutral_member": -0.5,
}
analyzer.lexicon.update(PHRASE_LEXICON)

# Normalize phrases to tokens before scoring
def preprocess_phrases(text: str) -> str:
    t = text
    for pat, token in PHRASE_PATTERNS.items():
        t = re.sub(pat, token, t, flags=re.IGNORECASE)
    return t

# =====================================================================
# 2) Contrastive handling (tail dominates)
# =====================================================================

CONTRAST_RE = re.compile(r"\b(but|however|although|though|yet|while|despite)\b", re.IGNORECASE)

NEG_TAIL_CUES_RE = re.compile(
    r"\b("
    r"challenge|challenges|concern|concerns|issue|issues|problem|problems|"
    r"delay|delays|late|inconsistent|inconsistency|"
    r"struggle|struggles|inflexible|conflict|"
    r"create_challenges|dominate_discussions|rush_through_tasks|"
    r"needs_improvement|room_for_improvement|delays_in_completing|"
    r"affects_overall_progress|inconsistencies|not_leading_role|quiet_understated"
    r")\b",
    re.IGNORECASE
)

def split_contrast(text: str) -> Tuple[str, Optional[str], Optional[str]]:
    m = CONTRAST_RE.search(text)
    if not m:
        return text, None, None
    head = text[:m.start()].strip()
    cue = m.group(0)
    tail = text[m.end():].strip()
    return head if head else "", cue, tail if tail else ""

def count_negative_cues(t: str) -> int:
    return len(list(NEG_TAIL_CUES_RE.finditer(t or "")))

# =====================================================================
# 3) Toxic-ish patterns
# =====================================================================

TOXIC_PATTERNS = [
    r"\b(toxic|idiot|stupid|dumb|useless|garbage|trash|shut\s*up|dumbass)\b",
    r"\b(hate|loser)\b",
]

# =====================================================================
# 4) Pydantic schemas
# =====================================================================

class AnalyzeIn(BaseModel):
    text: str

class AnalyzeOut(BaseModel):
    label: str
    score: float       # 0..1 confidence
    compound: float    # adjusted [-1..1] for debugging
    pos: float
    neu: float
    neg: float
    toxic: bool

class AnalyzeItemIn(BaseModel):
    id: str
    text: str

class AnalyzeBatchIn(BaseModel):
    items: List[AnalyzeItemIn]
    token: Optional[str] = None

class AnalyzeItemOut(BaseModel):
    id: str
    label: str
    score: float
    compound: float
    pos: float
    neu: float
    neg: float
    toxic: bool

class AnalyzeBatchOut(BaseModel):
    ok: bool
    results: List[AnalyzeItemOut]

class AnalyzeUnifiedIn(BaseModel):
    text: Optional[str] = None
    items: Optional[List[AnalyzeItemIn]] = None
    token: Optional[str] = None

# =====================================================================
# 5) Core helpers
# =====================================================================

def is_toxic(text: str, label: str, compound: float) -> bool:
    t = (text or "").lower()
    for pat in TOXIC_PATTERNS:
        if re.search(pat, t):
            return True
    return label == "negative" and abs(compound) >= 0.6

def sentence_scores(text: str):
    parts = re.split(r'(?<=[.!?])\s+', text.strip())
    return [analyzer.polarity_scores(p) for p in parts if p]

def contrast_tail_adjustment(text: str, s_all_compound: float) -> float:
    head, cue, tail = split_contrast(text)
    if not cue or not tail:
        return s_all_compound

    tail_scores = analyzer.polarity_scores(tail)
    tail_c = float(tail_scores["compound"])
    cues = count_negative_cues(tail)

    # If the tail contains >=3 negative cues, treat this as a clearly negative stance.
    if cues >= 3:
        enforced = min(tail_c, -0.20)  # small negative floor
        return 0.97 * enforced + 0.03 * s_all_compound

    # If tail appears negative or has any negative cue, trust it strongly.
    if tail_c < -0.05 or cues >= 1:
        return 0.95 * tail_c + 0.05 * s_all_compound

    # If tail appears positive but head negative, still bias towards tail.
    if tail_c > 0.05 and s_all_compound < -0.05:
        return 0.70 * tail_c + 0.30 * s_all_compound

    # Otherwise a mild nudge towards tail.
    return 0.60 * s_all_compound + 0.40 * tail_c

def adjusted_compound(raw_text: str) -> Tuple[float, dict]:
    # Normalize phrases first
    text = preprocess_phrases(raw_text)

    s_all = analyzer.polarity_scores(text)
    base_c = float(s_all["compound"])

    c_contrast = contrast_tail_adjustment(text, base_c)

    # Consider most extreme sentence (avoid over-smoothing long paragraphs)
    sents = sentence_scores(text)
    if sents:
        extreme_c = float(max(sents, key=lambda s: abs(s["compound"]))["compound"])
        c_final = 0.5 * c_contrast + 0.5 * extreme_c
    else:
        c_final = c_contrast

    # Clamp
    c_final = max(-1.0, min(1.0, c_final))
    return c_final, s_all

# =====================================================================
# 6) 0..1 confidence & label thresholds
# =====================================================================

def polarity_from_compound(c: float) -> float:
    """Map [-1..1] -> [0..1]."""
    return max(0.0, min(1.0, (c + 1.0) / 2.0))

def label_from_compound(c: float, pos_thr: float = 0.62, neg_thr: float = 0.44) -> str:
    """<=0.44 negative; 0.45..0.55 neutral; >=0.62 positive."""
    p = polarity_from_compound(c)
    if p >= pos_thr:
        return "positive"
    if p <= neg_thr:
        return "negative"
    return "neutral"

def confidence_from_label_and_compound(label: str, compound: float) -> float:
    """UI confidence is polarity [0..1]."""
    return round(polarity_from_compound(compound), 6)

# =====================================================================
# 6b) Neutral clamp for bland/steady text (NEW)
# =====================================================================

NEUTRAL_CUE_PATTERNS = [
    r"\bsteady\b", r"\bconsisten(t|cy)\b", r"\breliable\b", r"\bdependable\b",
    r"\bregular(ly)?\b", r"\bon\s*time\b", r"\bmeets?\s+expectations\b",
    r"\badequate\b", r"\bsatisfactory\b", r"\bprofessional\b",
    r"\bparticipat(es|e|ed)\b", r"\bcomplete(s|d)?\s+(their\s+)?assigned\s*tasks?\b",
    r"\bwithin\s+(the\s+)?(group|team)\b",
]

STRONG_POS_WORDS = [
    "excellent","outstanding","exceptional","amazing","brilliant","superb","fantastic",
    "remarkable","innovative","transformative","inspirational","exemplary","great",
    "phenomenal","goes above and beyond","proactive","initiative","leadership"
]

STRONG_NEG_WORDS = [
    "toxic","incompetent","useless","garbage","terrible","awful","unacceptable",
    "obstructive","dishonest","hostile","aggressive","disrespectful","rude",
    "lazy","unreliable","unresponsive","inflexible"
]

def _has_any_pattern(text: str, patterns: list[str]) -> bool:
    return any(re.search(p, text) for p in patterns)

def _has_any_word(text: str, words: list[str]) -> bool:
    for w in words:
        if " " in w:
            if w in text:
                return True
        else:
            if re.search(rf"\b{re.escape(w)}\b", text):
                return True
    return False

# =====================================================================
# 7) Analyzer
# =====================================================================

def analyze_text(text: str) -> AnalyzeOut:
    text = (text or "").strip()
    if not text:
        return AnalyzeOut(label="neutral", score=0.0, compound=0.0, pos=0.0, neu=1.0, neg=0.0, toxic=False)

    # Base adjusted sentiment (contrast handling + phrase normalization)
    comp, scores = adjusted_compound(text)

    # Neutral clamp: If there are neutral cues and no strong cues,
    # pull compound toward 0 so it maps to "neutral" with your thresholds.
    low = text.lower()
    has_neutral_cue = _has_any_pattern(low, NEUTRAL_CUE_PATTERNS)
    has_strong_pos  = _has_any_word(low, STRONG_POS_WORDS)
    has_strong_neg  = _has_any_word(low, STRONG_NEG_WORDS)

    if has_neutral_cue and not has_strong_pos and not has_strong_neg:
        comp *= 0.3
        if comp > 0.15:
            comp = 0.15
        if comp < -0.15:
            comp = -0.15

    # If intensity is small and text is fairly long, clamp slightly to neutral too
    if abs(comp) <= 0.35 and len(text) >= 140 and not (has_strong_pos or has_strong_neg):
        comp *= 0.5
        comp = max(-0.20, min(0.20, comp))

    # Final outputs
    label = label_from_compound(comp)                   # 0.62 / 0.44 thresholds
    conf  = confidence_from_label_and_compound(label, comp)  # 0..1 (no negatives)

    return AnalyzeOut(
        label=label,
        score=conf,
        compound=round(comp, 6),
        pos=round(float(scores["pos"]), 6),
        neu=round(float(scores["neu"]), 6),
        neg=round(float(scores["neg"]), 6),
        toxic=is_toxic(text, label, comp),
    )

# =====================================================================
# 8) API
# =====================================================================

class AnalyzeBatchOut(BaseModel):
    ok: bool
    results: List[AnalyzeItemOut]

@app.post("/analyze", response_model=AnalyzeOut | AnalyzeBatchOut)
def analyze_unified(payload: AnalyzeUnifiedIn, x_api_token: Optional[str] = Header(default=None)):
    # Batch path
    if payload.items is not None:
        expect = os.environ.get("SPE_API_TOKEN", "").strip()
        provided = (payload.token or x_api_token or "").strip()
        if expect and expect != provided:
            return AnalyzeBatchOut(ok=False, results=[])

        out: List[AnalyzeItemOut] = []
        for it in payload.items[:2000]:
            res = analyze_text(it.text)
            out.append(AnalyzeItemOut(
                id=it.id,
                label=res.label,
                score=res.score,
                compound=res.compound,
                pos=res.pos,
                neu=res.neu,
                neg=res.neg,
                toxic=res.toxic,
            ))
        return AnalyzeBatchOut(ok=True, results=out)

    # Single-text path
    if payload.text is not None:
        return analyze_text(payload.text)

    raise HTTPException(status_code=422, detail="Provide either 'text' or 'items'.")

if __name__ == "__main__":
    uvicorn.run("app:app", host="0.0.0.0", port=int(os.environ.get("PORT", "8000")), reload=True)