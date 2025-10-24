<?php
// ============================================================================
// SPE (Self & Peer Evaluation) - view.php
// - Renders "SPE1" form (Self + Peer evaluation)
// - Validates reflection (>=100 words)
// - Detects mismatches between numeric scores and comment sentiment
// - Saves to spe_submission / spe_rating
// - Queues text to spe_sentiment (for later NLP processing)
// - Draft autosave/restore via user preferences (requires mod/spe/draft.php)
// - NEW: Prevent double-submit + redirect to submission summary page
// ============================================================================

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/spe/view.php', ['id' => $cm->id]);
$PAGE->set_title('Self and Peer Evaluation');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');

// Instructor dashboard button (only for teachers/managers)
if (has_capability('mod/spe:manage', $context)) {
    $PAGE->set_button(
        $OUTPUT->single_button(
            new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]),
            get_string('instructordashboard', 'spe'),
            'get'
        )
    );
}

// ---------------------------------------------------------------------
// 0) Guard: prevent double-submit
// ---------------------------------------------------------------------
$existing = $DB->get_record('spe_submission', [
    'speid'  => $cm->instance,
    'userid' => $USER->id
], '*', IGNORE_MISSING);

if ($existing) {
    // Already submitted — send to the submission summary page
    $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
    redirect($submissionurl, get_string('alreadysubmitted', 'spe'), 2);
    exit;
}

// ---------------------------------------------------------------------
// 1) Header & page heading
// ---------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Self and Peer Evaluation');

// ---------------------------------------------------------------------
// 2) Helpers
// ---------------------------------------------------------------------

/** Quick heuristic sentiment just for consistency-warning gate. */
function spe_quick_sentiment(string $text): int {
    $text = core_text::strtolower($text);
    $pos = ['good','great','excellent','helpful','reliable','on time','timely','positive','clear','creative','well done','organised','responsible','supportive'];
    $neg = ['poor','bad','late','lazy','unresponsive','rude','toxic','negative','problem','issue','did not','lacking','absent','missing','unprofessional','conflict'];
    $p = 0; $n = 0;
    foreach ($pos as $w) { if (strpos($text, $w) !== false) { $p++; } }
    foreach ($neg as $w) { if (strpos($text, $w) !== false) { $n++; } }
    return $p - $n;
}

/** Unicode-safe word count (for min-words check). */
function spe_wordcount(string $text): int {
    if (preg_match_all("/[\\p{L}\\p{N}’']+/u", $text, $m)) {
        return count($m[0]);
    }
    return 0;
}

// Score thresholds
const SPE_SCORE_NEG_MAX = 13; // <=13 => negative
const SPE_SCORE_POS_MIN = 16; // >=16 => positive
const SPE_SCORE_MIN     = 5;  // theoretical min (5 criteria * 1)
const SPE_SCORE_MAX     = 25; // theoretical max (5 criteria * 5)

// Weighting if you ever combine text+points (kept for future use)
const SPE_WEIGHT_TEXT   = 0.5;
const SPE_WEIGHT_POINTS = 0.5;

// Sentiment thresholds (0..1 space)
const SPE_POS_THR = 0.62;
const SPE_NEG_THR = 0.44;

function spe_polarity_from_compound(float $c): float {
    $p = ($c + 1.0) / 2.0;
    return max(0.0, min(1.0, $p));
}
function spe_label_from_polarity(float $p): string {
    if ($p >= SPE_POS_THR) return 'positive';
    if ($p <= SPE_NEG_THR) return 'negative';
    return 'neutral';
}
function spe_label_from_compound(float $c): string {
    return spe_label_from_polarity(spe_polarity_from_compound($c));
}
function spe_points_polarity(?float $sum): ?float {
    if ($sum === null) return null;
    $p = ($sum - SPE_SCORE_MIN) / (SPE_SCORE_MAX - SPE_SCORE_MIN);
    return max(0.0, min(1.0, $p));
}
function spe_combined_polarity(float $textPol, ?float $pointsPol): float {
    if ($pointsPol === null) return $textPol;
    return (SPE_WEIGHT_TEXT * $textPol) + (SPE_WEIGHT_POINTS * $pointsPol);
}

// ---------------------------------------------------------------------
// 3) Static instructions & criteria
// ---------------------------------------------------------------------
echo html_writer::tag('div', '
    <p><strong>Please note:</strong> Everything that you put into this form will be kept strictly confidential by the unit coordinator.</p>
    <h4>Using the assessment scales</h4>
    <ul>
        <li>1 = Very poor / obstructive contribution</li>
        <li>2 = Poor contribution</li>
        <li>3 = Acceptable contribution</li>
        <li>4 = Good contribution</li>
        <li>5 = Excellent contribution</li>
    </ul>
', ['class' => 'spe-instructions']);

$criteria = [
    'effortdocs'    => 'The amount of work and effort put into the Requirements/Analysis Document, the Project Management Plan, and the Design Document.',
    'teamwork'      => 'Willingness to work as part of the group and taking responsibility.',
    'communication' => 'Communication within the group and participation in meetings.',
    'management'    => 'Contribution to management, e.g., work delivered on time.',
    'problemsolve'  => 'Problem solving and creativity for the group’s work.'
];

// ---------------------------------------------------------------------
// 4) Resolve the student's peers using Moodle Groups
// ---------------------------------------------------------------------
global $USER, $DB;

$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id);
if (!empty($usergroups[0])) {
    $mygroupid = reset($usergroups[0]);
    $members = groups_get_members($mygroupid, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if ((int)$u->id !== (int)$USER->id) {
            $peers[] = $u; // exclude self
        }
    }
} else {
    echo $OUTPUT->notification('You are not in any group yet. Please ask your instructor to add you to a group.', 'notifyproblem');
}

// ---------------------------------------------------------------------
// 5) Handle POST (validation, consistency gate, save, queue)
// ---------------------------------------------------------------------
$submitted          = optional_param('submitted', 0, PARAM_INT);
$confirmconsistency = optional_param('confirmconsistency', 0, PARAM_INT);

// Draft autosave preference
$draftkey  = 'mod_spe_draft_' . $cm->id;
$rawdraft  = (string) get_user_preferences($draftkey, '', $USER);
$draftdata = $rawdraft ? json_decode($rawdraft, true) : null;

// Prefill defaults
$prefill = [
    'selfdesc'   => '',
    'reflection' => '',
    'selfscores' => [],
    'peerscores' => [],
    'peertexts'  => []
];

// Merge draft (GET only)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $draftdata) {
    foreach (['selfdesc','reflection'] as $k) {
        if (!empty($draftdata[$k]) && is_string($draftdata[$k])) { $prefill[$k] = $draftdata[$k]; }
    }
    if (!empty($draftdata['selfscores']) && is_array($draftdata['selfscores'])) {
        $prefill['selfscores'] = array_merge($prefill['selfscores'], $draftdata['selfscores']);
    }
    if (!empty($draftdata['peerscores']) && is_array($draftdata['peerscores'])) {
        $prefill['peerscores'] = array_merge($prefill['peerscores'], $draftdata['peerscores']);
    }
    if (!empty($draftdata['peertexts']) && is_array($draftdata['peertexts'])) {
        $prefill['peertexts'] = array_merge($prefill['peertexts'], $draftdata['peertexts']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // 5.1 Collect inputs
    $selfdesc   = trim(optional_param('selfdesc', '', PARAM_RAW));
    $reflection = trim(optional_param('reflection', '', PARAM_RAW));

    $selfscores = [];
    foreach ($criteria as $key => $label) {
        $selfscores[$key] = optional_param("self_{$key}", 0, PARAM_INT);
    }

    $peerscores = [];   // [peerid => [criterion => score]]
    $peertexts  = [];   // [peerid => comment]
    foreach ($peers as $p) {
        $peertexts[$p->id] = trim(optional_param("comment_{$p->id}", '', PARAM_RAW_TRIMMED));
        foreach ($criteria as $key => $label) {
            $peerscores[$p->id][$key] = optional_param("peer_{$p->id}_{$key}", 0, PARAM_INT);
        }
    }

    // Keep for re-render if validation fails
    $prefill = [
        'selfdesc'   => $selfdesc,
        'reflection' => $reflection,
        'selfscores' => $selfscores,
        'peerscores' => $peerscores,
        'peertexts'  => $peertexts
    ];

    // 5.2 Validation — Reflection >= 100 words
    $errors   = [];
    $refwords = spe_wordcount($reflection);
    if ($refwords < 100) {
        $errors[] = "Reflection must be at least 100 words (currently $refwords).";
    }

    if (!empty($errors)) {
        foreach ($errors as $e) {
            echo $OUTPUT->notification($e, 'notifyproblem');
        }
        $submitted = 0; // render form again
    } else {
        // 5.3 Consistency detection (heuristic)
        $HIGH = 4.0;
        $LOW  = 2.5;
        $mismatches = [];
        $avg = function(array $arr): float {
            $vals = array_filter($arr, fn($v) => is_numeric($v) && $v > 0);
            return count($vals) ? array_sum($vals) / count($vals) : 0.0;
        };

        $selfavg  = $avg($selfscores);
        $selfsent = spe_quick_sentiment($selfdesc . ' ' . $reflection);
        if ($selfavg >= $HIGH && $selfsent < 0) {
            $mismatches[] = 'Your self scores are very high, but your description/reflection reads as negative.';
        }
        if ($selfavg <= $LOW && $selfsent > 0) {
            $mismatches[] = 'Your self scores are very low, but your description/reflection reads as positive.';
        }

        foreach ($peers as $p) {
            $pavg  = $avg($peerscores[$p->id] ?? []);
            $psent = spe_quick_sentiment($peertexts[$p->id] ?? '');
            if ($pavg >= $HIGH && $psent < 0) {
                $mismatches[] = 'High scores given for ' . fullname($p) . ' but the comment is negative.';
            }
            if ($pavg <= $LOW && $psent > 0) {
                $mismatches[] = 'Low scores given for ' . fullname($p) . ' but the comment is positive.';
            }
        }

        if (!empty($mismatches) && !$confirmconsistency) {
            echo $OUTPUT->notification('Potential inconsistencies detected. Please review your scores or comments:', 'notifyproblem');
            echo html_writer::start_tag('ul');
            foreach ($mismatches as $m) {
                echo html_writer::tag('li', s($m));
            }
            echo html_writer::end_tag('ul');

            // Minimal confirmation form to proceed anyway
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
                'style'  => 'margin-top:12px'
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submitted', 'value' => 1]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmconsistency', 'value' => 1]);

            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'selfdesc', 'value' => $selfdesc]);
            echo html_writer::tag('textarea', $reflection, ['name' => 'reflection', 'style' => 'display:none']);

            foreach ($criteria as $key => $label) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "self_{$key}", 'value' => $selfscores[$key]]);
            }
            foreach ($peers as $p) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "comment_{$p->id}", 'value' => $peertexts[$p->id]]);
                foreach ($criteria as $key => $label) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "peer_{$p->id}_{$key}", 'value' => $peerscores[$p->id][$key]]);
                }
            }
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Submit anyway (I confirm this is intended)']);
            echo html_writer::end_tag('form');

            echo html_writer::tag('p', html_writer::link(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]), 'Go back and edit your answers.'));
            echo $OUTPUT->footer();
            exit;
        }

        // 5.4 Save (valid + consistent or user confirmed)
        // Safety: block double-insert at the last moment (in case of race)
        if ($DB->record_exists('spe_submission', ['speid' => $cm->instance, 'userid' => $USER->id])) {
            $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
            redirect($submissionurl, get_string('alreadysubmitted', 'mod_spe'), 2);
            exit;
        }

        $data = [
            'speid'        => $cm->instance,
            'userid'       => $USER->id,
            'selfdesc'     => $selfdesc,
            'reflection'   => $reflection,
            'wordcount'    => spe_wordcount($reflection),
            'timecreated'  => time(),
            'timemodified' => time()
        ];
        $DB->insert_record('spe_submission', (object)$data);

        // Clear any previous ratings by this rater (idempotent)
        $DB->delete_records('spe_rating', ['speid' => $cm->instance, 'raterid' => $USER->id]);

        // Self scores (store selfdesc as the "comment")
        foreach ($criteria as $key => $label) {
            $score = $selfscores[$key] ?? 0;
            if ($score >= 1 && $score <= 5) {
                $DB->insert_record('spe_rating', (object)[
                    'speid'       => $cm->instance,
                    'raterid'     => $USER->id,
                    'rateeid'     => $USER->id,
                    'criterion'   => $key,
                    'score'       => $score,
                    'comment'     => $selfdesc ?: null,
                    'timecreated' => time()
                ]);
            }
        }

        // Peer scores + comments
        foreach ($peers as $p) {
            $peercomment = $peertexts[$p->id] ?? '';
            foreach ($criteria as $key => $label) {
                $score = $peerscores[$p->id][$key] ?? 0;
                if ($score >= 1 && $score <= 5) {
                    $DB->insert_record('spe_rating', (object)[
                        'speid'       => $cm->instance,
                        'raterid'     => $USER->id,
                        'rateeid'     => $p->id,
                        'criterion'   => $key,
                        'score'       => $score,
                        'comment'     => $peercomment ?: null,
                        'timecreated' => time()
                    ]);
                }
            }

            // Queue peer comment for NLP (if table exists)
            if ($DB->get_manager()->table_exists('spe_sentiment') && $peercomment !== '') {
                $DB->insert_record('spe_sentiment', (object)[
                    'speid'       => $cm->instance,
                    'raterid'     => $USER->id,
                    'rateeid'     => $p->id,
                    'type'        => 'peer_comment',
                    'text'        => $peercomment,
                    'status'      => 'pending',
                    'timecreated' => time()
                ]);
            }
        }

        // Queue reflection as well (clear any pending duplicates for this user)
        if ($DB->get_manager()->table_exists('spe_sentiment') && $reflection !== '') {
            $DB->delete_records('spe_sentiment', [
                'speid'   => $cm->instance,
                'raterid' => $USER->id,
                'rateeid' => $USER->id,
                'type'    => 'reflection',
                'status'  => 'pending'
            ]);
            $DB->insert_record('spe_sentiment', (object)[
                'speid'       => $cm->instance,
                'raterid'     => $USER->id,
                'rateeid'     => $USER->id,
                'type'        => 'reflection',
                'text'        => $reflection,
                'status'      => 'pending',
                'timecreated' => time()
            ]);
        }

        // SUCCESS — clear draft and redirect to summary page
        unset_user_preference($draftkey, $USER);

        $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
        redirect($submissionurl, 'Your submission has been saved successfully!', 2);
        exit;
    }
}

// ---------------------------------------------------------------------
// 6) Render the form (prefilled if validation failed / or draft merged on GET)
// ---------------------------------------------------------------------
if (!$submitted) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submitted', 'value' => 1]);

    // Self Evaluation
    echo html_writer::tag('h3', 'Self Evaluation');
    $sel = $prefill['selfscores'] ?? [];
    foreach ($criteria as $key => $label) {
        echo html_writer::tag('p', $label);
        echo '<select name="self_' . $key . '" required>';
        echo '<option value="">--</option>';
        for ($i = 1; $i <= 5; $i++) {
            $selected = (isset($sel[$key]) && (int)$sel[$key] === $i) ? ' selected' : '';
            echo "<option value=\"$i\"$selected>$i</option>";
        }
        echo '</select><br>';
    }

    echo html_writer::tag('h4', 'Briefly describe how you believe you contributed to the project process:');
    echo html_writer::tag('textarea', $prefill['selfdesc'] ?? '', ['name' => 'selfdesc', 'rows' => 4, 'cols' => 80]);

    echo html_writer::tag('h4', 'Reflection (minimum 100 words)');
    echo html_writer::tag('textarea', $prefill['reflection'] ?? '', ['name' => 'reflection', 'rows' => 6, 'cols' => 80]);

    // Peer Evaluation
    if (!empty($peers)) {
        echo html_writer::tag('h3', 'Evaluation of Team Members');

        $psel  = $prefill['peerscores'] ?? [];
        $ptext = $prefill['peertexts']  ?? [];

        foreach ($peers as $p) {
            echo html_writer::tag('h4', 'Member: ' . fullname($p) . " ({$p->username})");
            foreach ($criteria as $key => $label) {
                echo html_writer::tag('p', $label);
                echo '<select name="peer_' . $p->id . '_' . $key . '" required>';
                echo '<option value="">--</option>';
                for ($i = 1; $i <= 5; $i++) {
                    $selected = (isset($psel[$p->id][$key]) && (int)$psel[$p->id][$key] === $i) ? ' selected' : '';
                    echo "<option value=\"$i\"$selected>$i</option>";
                }
                echo '</select><br>';
            }
            echo html_writer::tag('p', 'Briefly describe how you believe this person contributed to the project process:');
            echo html_writer::tag('textarea', $ptext[$p->id] ?? '', ['name' => "comment_{$p->id}", 'rows' => 4, 'cols' => 80]);
            echo html_writer::empty_tag('hr');
        }
    } else {
        echo $OUTPUT->notification('No peers found in your group. You can still submit your self-evaluation.', 'notifywarning');
    }

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Submit']);
    echo html_writer::end_tag('form');

    // ---------- AUTOSAVE DRAFT (JS) ----------
    ?>
    <script>
    (function(){
      const form = document.querySelector('form[action*="/mod/spe/view.php"]');
      if(!form) return;

      const cmid = <?php echo (int)$cm->id; ?>;
      const sesskeyVal = "<?php echo sesskey(); ?>";
      const draftUrl = M.cfg.wwwroot + "/mod/spe/draft.php?id=" + cmid + "&sesskey=" + encodeURIComponent(sesskeyVal);

      function readFormJSON() {
        const data = {
          selfdesc: form.querySelector('[name="selfdesc"]')?.value || '',
          reflection: form.querySelector('[name="reflection"]')?.value || '',
          selfscores: {},
          peerscores: {},
          peertexts: {}
        };

        form.querySelectorAll('select[name^="self_"]').forEach(sel => {
          const key = sel.name.replace(/^self_/, '');
          data.selfscores[key] = sel.value ? parseInt(sel.value, 10) : 0;
        });

        form.querySelectorAll('select[name^="peer_"]').forEach(sel => {
          const parts = sel.name.split('_'); // ["peer", "{id}", "{key}"]
          if (parts.length >= 3) {
            const pid = parts[1];
            const key = parts.slice(2).join('_');
            if (!data.peerscores[pid]) data.peerscores[pid] = {};
            data.peerscores[pid][key] = sel.value ? parseInt(sel.value, 10) : 0;
          }
        });

        form.querySelectorAll('textarea[name^="comment_"]').forEach(t => {
          const pid = t.name.replace(/^comment_/, '');
          data.peertexts[pid] = t.value || '';
        });

        const json = JSON.stringify(data);
        if (json.length > 180000) {
          try {
            const d = JSON.parse(json);
            d.reflection = (d.reflection || '').slice(0, 30000);
            for (const k in d.peertexts) {
              d.peertexts[k] = (d.peertexts[k] || '').slice(0, 15000);
            }
            return JSON.stringify(d);
          } catch(e) { return JSON.stringify({}); }
        }
        return json;
      }

      let saveTimer = null, lastSent = '';
      function queueSave() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(async () => {
          const body = readFormJSON();
          if (body === lastSent) return;
          lastSent = body;
          try {
            await fetch(draftUrl + "&action=save", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              body
            });
          } catch(e) { /* ignore autosave errors */ }
        }, 800);
      }

      form.addEventListener('input', queueSave);
      form.addEventListener('change', queueSave);

      // Client-side restore (fills blanks only)
      (async function restoreDraft(){
        try {
          const res = await fetch(draftUrl + "&action=load");
          const data = await res.json();
          if (!data || data.exists === false) return;

          if (data.selfdesc && !form.selfdesc?.value) form.selfdesc.value = data.selfdesc;
          if (data.reflection && !form.reflection?.value) form.reflection.value = data.reflection;

          if (data.selfscores) {
            Object.keys(data.selfscores).forEach(k => {
              const el = form.querySelector(`[name="self_${k}"]`);
              if (el && !el.value) el.value = data.selfscores[k] || '';
            });
          }
          if (data.peerscores) {
            Object.keys(data.peerscores).forEach(pid => {
              const obj = data.peerscores[pid];
              Object.keys(obj || {}).forEach(k => {
                const el = form.querySelector(`[name="peer_${pid}_${k}"]`);
                if (el && !el.value) el.value = obj[k] || '';
              });
            });
          }
          if (data.peertexts) {
            Object.keys(data.peertexts).forEach(pid => {
              const el = form.querySelector(`[name="comment_${pid}"]`);
              if (el && !el.value) el.value = data.peertexts[pid] || '';
            });
          }
        } catch(e) { /* ignore */ }
      })();
    })();
    </script>
    <?php
    // ---------- END AUTOSAVE DRAFT (JS) ----------
}

// ---------------------------------------------------------------------
// 7) Footer
// ---------------------------------------------------------------------
echo $OUTPUT->footer();
