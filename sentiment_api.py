# sentiment_api.py
# Unified FastAPI service: live + batch sentiment + word count
# Includes score/comment disparity detection.
# Run: uvicorn sentiment_api:app --reload --host 0.0.0.0 --port 8000

from typing import List, Optional, Tuple
import os
import re

from fastapi import FastAPI, Header, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer
import uvicorn

# -----------------------------------------------------------------------------
# Config / thresholds
# -----------------------------------------------------------------------------
SCORE_MIN_DEFAULT = 5      # 5 criteria * 1
SCORE_MAX_DEFAULT = 25     # 5 criteria * 5

# Disparity triggers
DISPARITY_LOW_MIN = 5
DISPARITY_LOW_MAX = 10
DISPARITY_HIGH_MIN = 20
DISPARITY_HIGH_MAX = 25

POS_THR = 0.62  # positivity threshold in 0..1 polarity space
NEG_THR = 0.44  # negativity threshold in 0..1 polarity space

# -----------------------------------------------------------------------------
# App setup (CORS enabled so the browser page can call this API directly)
# -----------------------------------------------------------------------------
app = FastAPI(title="SPE Sentiment API (Live + Batch)", version="1.1.1")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],              # In production, restrict to your Moodle origin
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

analyzer = SentimentIntensityAnalyzer()

# -----------------------------------------------------------------------------
# Lexicon & phrase-level penalties (polite academic negatives)
# -----------------------------------------------------------------------------
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
    "dominating": -2.8, "dominant": -2.6,
    "needs": -1.2, "improvement": -1.2, "improve": -1.2, "improving": -1.0,
    "blocking": -2.9, "obstructive": -3.2,
    "conflict": -2.9, "frustrating": -3.0, "frustration": -3.0,
}
analyzer.lexicon.update(CUSTOM_WEAK_NEG)

PHRASE_PATTERNS = {
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
    "strong_opinions": -1.4,
    "inflexible": -3.0,
    "time_mgmt_could_improve": -2.6,
    "delays_in_completing": -2.8,
    "affects_overall_progress": -2.6,
    "needs_improvement": -2.9,
    "room_for_improvement": -2.2,
    "not_leading_role": -0.8,
    "quiet_understated": -0.6,
    "neutral_member": -0.5,
}
analyzer.lexicon.update(PHRASE_LEXICON)

def preprocess_phrases(text: str) -> str:
    t = text
    for pat, token in PHRASE_PATTERNS.items():
        t = re.sub(pat, token, t, flags=re.IGNORECASE)
    return t

# -----------------------------------------------------------------------------
# Contrastive handling (tail dominates)
# -----------------------------------------------------------------------------
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

# -----------------------------------------------------------------------------
# Toxic patterns
# -----------------------------------------------------------------------------
TOXIC_PATTERNS = [
    r"\b(dumb(?:-|\s*)ass|dumbass|idiot|stupid|moron|retard(?:ed)?)\b",
    r"\b(useless|garbage|trash|loser|worthless)\b",
    r"\b(asshole|prick|dick|bitch|cunt|whore|slut)\b",
    r"\b(fuck(?:ing)?|shit|bullshit|damn|bloody)\b",
    r"\b(hate|hostile|toxic)\b",
    r"\b(shut\s*up)\b",
]
TOXIC_RE = re.compile("|".join(TOXIC_PATTERNS), re.IGNORECASE)

def is_toxic(text: str) -> bool:
    return bool(TOXIC_RE.search(text or ""))

# -----------------------------------------------------------------------------
# Labeling & utility
# -----------------------------------------------------------------------------
def polarity_from_compound(c: float) -> float:
    return max(0.0, min(1.0, (c + 1.0) / 2.0))

def label_from_compound(c: float) -> str:
    p = polarity_from_compound(c)
    if p >= POS_THR:
        return "positive"
    if p <= NEG_THR:
        return "negative"
    return "neutral"

def sentence_scores(text: str):
    parts = re.split(r'(?<=[.!?])\s+', (text or "").strip())
    return [analyzer.polarity_scores(p) for p in parts if p]

def contrast_tail_adjustment(text: str, s_all_compound: float) -> float:
    _, cue, tail = split_contrast(text)
    if not cue or not tail:
        return s_all_compound
    tail_scores = analyzer.polarity_scores(tail)
    tail_c = float(tail_scores["compound"])
    cues = count_negative_cues(tail)
    if cues >= 3:
        enforced = min(tail_c, -0.20)
        return 0.97 * enforced + 0.03 * s_all_compound
    if tail_c < -0.05 or cues >= 1:
        return 0.95 * tail_c + 0.05 * s_all_compound
    if tail_c > 0.05 and s_all_compound < -0.05:
        return 0.70 * tail_c + 0.30 * s_all_compound
    return 0.60 * s_all_compound + 0.40 * tail_c

def adjusted_compound(raw_text: str) -> Tuple[float, dict]:
    text = preprocess_phrases(raw_text or "")
    s_all = analyzer.polarity_scores(text)
    base_c = float(s_all["compound"])
    c_contrast = contrast_tail_adjustment(text, base_c)
    sents = sentence_scores(text)
    if sents:
        extreme_c = float(max(sents, key=lambda s: abs(s["compound"]))["compound"])
        c_final = 0.5 * c_contrast + 0.5 * extreme_c
    else:
        c_final = c_contrast
    return max(-1.0, min(1.0, c_final)), s_all

def word_char_counts(text: str) -> Tuple[int, int]:
    words = re.findall(r"\b\w+\b", text or "")
    return len(words), len(text or "")

# -----------------------------------------------------------------------------
# Schemas (with score_total support)
# -----------------------------------------------------------------------------
class AnalyzeIn(BaseModel):
    text: Optional[str] = None
    score_total: Optional[float] = None
    score_min: Optional[float] = None
    score_max: Optional[float] = None

class AnalyzeItemIn(BaseModel):
    id: str
    text: str
    score_total: Optional[float] = None
    score_min: Optional[float] = None
    score_max: Optional[float] = None

class AnalyzeUnifiedIn(BaseModel):
    text: Optional[str] = None
    items: Optional[List[AnalyzeItemIn]] = None
    token: Optional[str] = None
    score_total: Optional[float] = None
    score_min: Optional[float] = None
    score_max: Optional[float] = None

class AnalyzeOut(BaseModel):
    label: str
    score: float          # 0..1 (alias of confidence)
    confidence: float     # 0..1
    compound: float       # [-1..1]
    pos: float
    neu: float
    neg: float
    toxic: bool
    word_count: int
    char_count: int
    # disparity fields
    disparity: bool
    disparity_reason: Optional[str] = None
    suggest_confirm: bool

class AnalyzeItemOut(AnalyzeOut):
    id: str

class AnalyzeBatchOut(BaseModel):
    ok: bool
    results: List[AnalyzeItemOut]

# -----------------------------------------------------------------------------
# Disparity evaluation
# -----------------------------------------------------------------------------
def evaluate_disparity(
        label: str,
        score_total: Optional[float],
        score_min: float,
        score_max: float
) -> Tuple[bool, Optional[str], bool]:
    """
    Returns (disparity, reason, suggest_confirm)

    Rules:
    - Low total (5..10) but **positive** text -> disparity
    - High total (20..25) but **negative/toxic** text -> disparity
    """
    if score_total is None:
        return False, None, False

    try:
        st = float(score_total)
    except (TypeError, ValueError):
        return False, None, False

    # Only consider totals within configured bounds
    if st < score_min or st > score_max:
        return False, None, False

    if DISPARITY_LOW_MIN <= st <= DISPARITY_LOW_MAX and label == "positive":
        reason = f"Your total score is {int(st)}, but your comment reads as {label}. Do you want to continue with submission?"
        return True, reason, True

    if DISPARITY_HIGH_MIN <= st <= DISPARITY_HIGH_MAX and label in ("negative", "toxic"):
        reason = f"Your total score is {int(st)}, but your comment reads as {label}. Do you want to continue with submission?"
        return True, reason, True

    return False, None, False

# -----------------------------------------------------------------------------
# Core analyzer (single text -> full result incl. counts + disparity)
# -----------------------------------------------------------------------------
def analyze_text_full(
        text: str,
        score_total: Optional[float],
        score_min: Optional[float],
        score_max: Optional[float]
) -> AnalyzeOut:
    tx = (text or "").strip()
    wc, cc = word_char_counts(tx)

    smin = float(score_min) if score_min is not None else SCORE_MIN_DEFAULT
    smax = float(score_max) if score_max is not None else SCORE_MAX_DEFAULT

    if not tx:
        lbl = "neutral"
        conf = 0.5
        disp, reason, confirm = evaluate_disparity(lbl, score_total, smin, smax)
        return AnalyzeOut(
            label=lbl, score=conf, confidence=conf, compound=0.0,
            pos=0.0, neu=1.0, neg=0.0, toxic=False,
            word_count=wc, char_count=cc,
            disparity=disp, disparity_reason=reason, suggest_confirm=confirm,
        )

    comp, scores = adjusted_compound(tx)

    # Neutral clamp for bland/steady text
    NEUTRAL_CUE_PATTERNS = [
        r"\bsteady\b", r"\bconsisten(t|cy)\b", r"\breliable\b", r"\bdependable\b",
        r"\bregular(ly)?\b", r"\bon\s*time\b", r"\bmeets?\s+expectations\b",
        r"\badequate\b", r"\bsatisfactory\b", r"\bprofessional\b",
        r"\bparticipat(es|e|ed)\b", r"\bcomplete(s|d)?\s+(their\s+)?assigned\s*tasks?\b",
        r"\bwithin\s+(the\s+)?(group|team)\b",
    ]
    STRONG_POS_WORDS = [
        "excellent", "outstanding", "exceptional", "amazing", "brilliant", "superb", "fantastic",
        "remarkable", "innovative", "transformative", "inspirational", "exemplary", "great",
        "phenomenal", "goes above and beyond", "proactive", "initiative", "leadership"
    ]
    STRONG_NEG_WORDS = [
        "toxic", "incompetent", "useless", "garbage", "terrible", "awful", "unacceptable",
        "obstructive", "dishonest", "hostile", "aggressive", "disrespectful", "rude",
        "lazy", "unreliable", "unresponsive", "inflexible"
    ]

    def _has_any_pattern(t: str, pats: List[str]) -> bool:
        return any(re.search(p, t) for p in pats)

    def _has_any_word(t: str, words: List[str]) -> bool:
        for w in words:
            if " " in w:
                if w in t:
                    return True
            else:
                if re.search(rf"\b{re.escape(w)}\b", t):
                    return True
        return False

    low = tx.lower()
    has_neutral_cue = _has_any_pattern(low, NEUTRAL_CUE_PATTERNS)
    has_strong_pos  = _has_any_word(low, STRONG_POS_WORDS)
    has_strong_neg  = _has_any_word(low, STRONG_NEG_WORDS)

    if has_neutral_cue and not has_strong_pos and not has_strong_neg:
        comp *= 0.3
        comp = max(-0.15, min(0.15, comp))

    if abs(comp) <= 0.35 and len(tx) >= 140 and not (has_strong_pos or has_strong_neg):
        comp *= 0.5
        comp = max(-0.20, min(0.20, comp))

    label = label_from_compound(comp)

    # Toxic override
    toxic_flag = is_toxic(tx)
    if toxic_flag and comp > -0.60:
        comp = -0.60
        label = "toxic"

    conf = round(polarity_from_compound(comp), 6)

    # Disparity detection (uses final label)
    disparity, reason, suggest = evaluate_disparity(label, score_total, smin, smax)

    return AnalyzeOut(
        label=label,
        score=conf,
        confidence=conf,
        compound=round(comp, 6),
        pos=round(float(scores["pos"]), 6),
        neu=round(float(scores["neu"]), 6),
        neg=round(float(scores["neg"]), 6),
        toxic=toxic_flag,
        word_count=wc,
        char_count=cc,
        disparity=disparity,
        disparity_reason=reason,
        suggest_confirm=suggest,
    )

# -----------------------------------------------------------------------------
# API
# -----------------------------------------------------------------------------
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
            r = analyze_text_full(
                text=it.text,
                score_total=it.score_total,
                score_min=it.score_min,
                score_max=it.score_max
            )
            out.append(AnalyzeItemOut(
                id=it.id,
                label=r.label,
                score=r.score,
                confidence=r.confidence,
                compound=r.compound,
                pos=r.pos, neu=r.neu, neg=r.neg,
                toxic=r.toxic,
                word_count=r.word_count,
                char_count=r.char_count,
                disparity=r.disparity,
                disparity_reason=r.disparity_reason,
                suggest_confirm=r.suggest_confirm,
            ))
        return AnalyzeBatchOut(ok=True, results=out)

    # Single-text path
    if payload.text is not None:
        return analyze_text_full(
            text=payload.text,
            score_total=payload.score_total,
            score_min=payload.score_min,
            score_max=payload.score_max
        )

    raise HTTPException(status_code=422, detail="Provide either 'text' or 'items'.")

# -----------------------------------------------------------------------------
# Local dev entrypoint
# -----------------------------------------------------------------------------
if __name__ == "__main__":
    uvicorn.run("sentiment_api:app", host="0.0.0.0", port=int(os.environ.get("PORT", "8000")), reload=True)