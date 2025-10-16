<?php
// ============================================================================
// SPE (Self & Peer Evaluation) - view.php
// - Renders "SPE1" form (Self + Peer evaluation)
// - Validates reflection (>=100 words)
// - Detects mismatches between numeric scores and comment sentiment
// - Saves to spe_submission / spe_rating
// - Queues text to spe_sentiment and immediately calls FastAPI
// - Shows a pop-out modal with text sentiment + points + combined final
// ============================================================================

require('../../config.php');

// ---------------------------------------------------------------------
// 1) Resolve context and set up page
// ---------------------------------------------------------------------
$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/spe/view.php', ['id' => $cm->id]);
$PAGE->set_title('Self and Peer Evaluation');
$PAGE->set_heading($course->fullname);

// Render header
echo $OUTPUT->header();
echo $OUTPUT->heading('Self and Peer Evaluation');

// ---------------------------------------------------------------------
// 2) Helpers
// ---------------------------------------------------------------------

/**
 * Quick heuristic sentiment for consistency-warning gate only.
 * Returns an integer: >0 positive, <0 negative, 0 neutral.
 */
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

// ---- Numeric-score thresholds/weights (you can tweak) ----
const SPE_SCORE_NEG_MAX = 13; // <=13 => negative
const SPE_SCORE_POS_MIN = 16; // >=16 => positive
const SPE_SCORE_MIN     = 5;  // theoretical min (5 criteria * 1)
const SPE_SCORE_MAX     = 25; // theoretical max (5 criteria * 5)

// How to mix text & points: weights (must sum to 1)
const SPE_WEIGHT_TEXT   = 0.5;
const SPE_WEIGHT_POINTS = 0.5;

// Sentiment thresholds (on 0..1 polarity space)
const SPE_POS_THR = 0.62;
const SPE_NEG_THR = 0.44;

/** Map VADER compound [-1..1] -> polarity [0..1]. */
function spe_polarity_from_compound(float $c): float {
    $p = ($c + 1.0) / 2.0;
    return max(0.0, min(1.0, $p));
}

/** Label from 0..1 polarity thresholds. */
function spe_label_from_polarity(float $p): string {
    if ($p >= SPE_POS_THR) return 'positive';
    if ($p <= SPE_NEG_THR) return 'negative';
    return 'neutral';
}

/** Label directly from compound via polarity thresholds. */
function spe_label_from_compound(float $c): string {
    return spe_label_from_polarity(spe_polarity_from_compound($c));
}

/** Normalize a total points sum (5..25) to [0..1]. */
function spe_points_polarity(?float $sum): ?float {
    if ($sum === null) return null;
    $p = ($sum - SPE_SCORE_MIN) / (SPE_SCORE_MAX - SPE_SCORE_MIN);
    return max(0.0, min(1.0, $p));
}

/** Label from raw points sum using your buckets. */
function spe_points_label(?float $sum): ?string {
    if ($sum === null) return null;
    if ($sum >= SPE_SCORE_POS_MIN) return 'positive';
    if ($sum <= SPE_SCORE_NEG_MAX) return 'negative';
    return 'neutral';
}

/** Combine text polarity + points polarity into one [0..1]. */
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
        <li>1 = Very poor, or even obstructive, contribution to the project process</li>
        <li>2 = Poor contribution to the project process</li>
        <li>3 = Acceptable contribution to the project process</li>
        <li>4 = Good contribution to the project process</li>
        <li>5 = Excellent contribution to the project process</li>
    </ul>
', ['class' => 'spe-instructions']);

$criteria = [
    'effortdocs'    => 'The amount of work and effort put into the Requirements and Analysis Document, the Project Management Plan, and the Design Document.',
    'teamwork'      => 'Willingness to work as part of the group and taking responsibility in the group.',
    'communication' => 'Communication within the group and participation in group meetings.',
    'management'    => 'Contribution to the management of the project, e.g. work delivered on time.',
    'problemsolve'  => 'Problem solving and creativity on behalf of the group’s work.'
];

// ---------------------------------------------------------------------
// 4) Resolve the student's peers using Moodle Groups
// ---------------------------------------------------------------------
global $USER, $DB;

$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id); // [0] => array of groupids
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
// 5) Handle POST (validation, consistency-gate, save, queue)
// ---------------------------------------------------------------------
$submitted          = optional_param('submitted', 0, PARAM_INT);
$confirmconsistency = optional_param('confirmconsistency', 0, PARAM_INT);

// Prefill vars if validation fails.
$prefill = [
    'selfdesc'   => '',
    'reflection' => '',
    'selfscores' => [],
    'peerscores' => [],
    'peertexts'  => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // ---- 5.1 Collect inputs once ----
    $selfdesc   = trim(optional_param('selfdesc', '', PARAM_RAW));
    $reflection = trim(optional_param('reflection', '', PARAM_RAW));

    $selfscores = [];
    foreach ($criteria as $key => $label) {
        $selfscores[$key] = optional_param("self_{$key}", 0, PARAM_INT);
    }

    $peerscores = [];   // [peerid => [criterion => score]]
    $peertexts  = [];   // [peerid => comment text]
    foreach ($peers as $p) {
        $peertexts[$p->id] = trim(optional_param("comment_{$p->id}", '', PARAM_RAW_TRIMMED));
        foreach ($criteria as $key => $label) {
            $peerscores[$p->id][$key] = optional_param("peer_{$p->id}_{$key}", 0, PARAM_INT);
        }
    }

    // Keep for prefill if we fail validation.
    $prefill = [
        'selfdesc'   => $selfdesc,
        'reflection' => $reflection,
        'selfscores' => $selfscores,
        'peerscores' => $peerscores,
        'peertexts'  => $peertexts
    ];

    // ---- 5.2 Validation: Reflection min 100 words ----
    $errors   = [];
    $refwords = spe_wordcount($reflection);
    if ($refwords < 100) {
        $errors[] = "Reflection must be at least 100 words (currently $refwords).";
    }

    if (!empty($errors)) {
        foreach ($errors as $e) {
            echo $OUTPUT->notification($e, 'notifyproblem');
        }
        $submitted = 0; // force form to render
    } else {
        // ---- 5.3 Consistency detection ----
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
            $mismatches[] = 'Your self scores are very high, but your self-description/reflection reads as negative.';
        }
        if ($selfavg <= $LOW && $selfsent > 0) {
            $mismatches[] = 'Your self scores are very low, but your self-description/reflection reads as positive.';
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

            // Minimal confirmation form resubmits same data without retyping
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

        // ---- 5.4 Save (valid + either consistent or user confirmed) ----
        $sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $USER->id]);
        $data = [
            'speid'        => $cm->instance,
            'userid'       => $USER->id,
            'selfdesc'     => $selfdesc,
            'reflection'   => $reflection,
            'wordcount'    => spe_wordcount($reflection),
            'timemodified' => time()
        ];
        if ($sub) {
            $data['id'] = $sub->id;
            $DB->update_record('spe_submission', (object)$data);
        } else {
            $data['timecreated'] = time();
            $DB->insert_record('spe_submission', (object)$data);
        }

        // Clear old ratings to make submission idempotent
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

        // Peer scores + comment
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

            // Queue peer comment for later NLP if table exists
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

        // Queue reflection as well (dedupe pending before reinsert)
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

        echo $OUTPUT->notification('Your submission has been saved successfully!', 'notifysuccess');
        $submitted = 1;
    }
}

// ---------------------------------------------------------------------
// 6) Render the form (prefilled if validation failed)
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
}

// ============================================================================
// APPENDED SECTION: Instant Sentiment Analysis + Pop-out Modal
// ============================================================================
require_once($CFG->libdir . '/filelib.php'); // Moodle curl helper

if (!empty($submitted) && $submitted == 1) {

    // Collect texts that were just queued in spe_sentiment for THIS user and attempt analysis now.
    $rows = $DB->get_records('spe_sentiment', [
        'speid'   => $cm->instance,
        'raterid' => $USER->id,
        'status'  => 'pending'
    ]);

    if ($rows) {
        $items = [];
        foreach ($rows as $r) {
            $items[] = ['id' => (string)$r->id, 'text' => (string)$r->text];
        }

        // Prepare FastAPI call (URL+token from plugin settings)
        $cfgurl   = trim((string)get_config('mod_spe', 'sentiment_url'));
        $cfgtoken = trim((string)get_config('mod_spe', 'sentiment_token')); // optional

        if ($cfgurl !== '') {
            $payload = json_encode(['items' => $items, 'token' => null]); // raw JSON
            $curl    = new curl();

            // Build headers & pass via CURLOPT_HTTPHEADER (important for FastAPI)
            $headers = ['Content-Type: application/json'];
            if ($cfgtoken !== '') {
                $headers[] = 'X-API-TOKEN: ' . $cfgtoken;
            }

            try {
                $opts = [
                    'CURLOPT_HTTPHEADER' => $headers,
                    'CURLOPT_TIMEOUT'    => 30,
                ];
                $response = $curl->post($cfgurl, $payload, $opts);

                $data = json_decode($response);

                // Normalize if single-object accidentally returned
                if (is_object($data) && isset($data->label) && !isset($data->ok)) {
                    $data = (object)[
                        'ok'      => true,
                        'results' => [(object)array_merge(['id' => (string)$items[0]['id']], (array)$data)]
                    ];
                }

                if (is_object($data) && property_exists($data, 'ok') && $data->ok === true) {
                    // Update DB with results and derive label here (consistent thresholds)
                    $processedids = [];
                    foreach ($data->results as $res) {
                        $id = (int)$res->id;
                        $processedids[] = $id;

                        if ($row = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance])) {
                            $row->sentiment    = (float)$res->compound;                 // raw compound
                            $row->label        = spe_label_from_compound($row->sentiment);
                            $row->status       = 'done';
                            $row->timemodified = time();
                            $DB->update_record('spe_sentiment', $row);
                        }
                    }

                    // Build modal contents using only the rows we just analyzed
                    $done = [];
                    if ($processedids) {
                        list($insql, $inparams) = $DB->get_in_or_equal($processedids, SQL_PARAMS_NAMED, 'sid');
                        $done = $DB->get_records_select('spe_sentiment', "id $insql", $inparams);
                    }

                    // Map user names (rater + ratee)
                    $userids = [];
                    foreach ($done as $r) {
                        $userids[$r->raterid] = true;
                        $userids[$r->rateeid] = true;
                    }
                    $users = [];
                    if ($userids) {
                        list($uinsql, $uinparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
                        $users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', 'id, firstname, lastname, username');
                    }

                    // Fetch total numeric points per (rater -> ratee) for this SPE and current user.
                    $pointsbyratee = []; // [rateeid => total_points]
                    $ptsrecs = $DB->get_records_sql(
                        "SELECT rateeid, SUM(score) AS total
                           FROM {spe_rating}
                          WHERE speid = :speid AND raterid = :rater
                       GROUP BY rateeid",
                        ['speid' => $cm->instance, 'rater' => $USER->id]
                    );
                    foreach ($ptsrecs as $rec) {
                        $pointsbyratee[(int)$rec->rateeid] = (float)$rec->total;
                    }

                    // Badge renderer
                    $badge = function(string $label): string {
                        $style = 'background:#6c757d;';
                        if ($label === 'positive') { $style = 'background:#1a7f37;'; }
                        if ($label === 'negative') { $style = 'background:#b42318;'; }
                        return 'display:inline-block;color:#fff;padding:2px 8px;border-radius:12px;font-size:12px;' . $style;
                    };

                    // Table rows
                    $rowshtml = '';
                    foreach ($done as $r) {
                        $rname = isset($users[$r->raterid])  ? fullname($users[$r->raterid])  : $r->raterid;
                        $tname = isset($users[$r->rateeid]) ? fullname($users[$r->rateeid]) : $r->rateeid;

                        // TEXT (from API compound)
                        $textPol = spe_polarity_from_compound((float)$r->sentiment); // 0..1
                        $textLbl = spe_label_from_polarity($textPol);
                        $lblText = html_writer::tag('span', s($textLbl), ['style' => $badge($textLbl)]);
                        $textScore = sprintf('%.3f', $textPol);

                        // POINTS (sum of 5 criteria for this rater -> this ratee)
                        $sumpts = $pointsbyratee[(int)$r->rateeid] ?? null;
                        $sumptsDisplay = ($sumpts === null) ? '-' : (string)(int)$sumpts;

                        // FINAL combined
                        $ptsPol    = spe_points_polarity($sumpts);
                        $finalPol  = spe_combined_polarity($textPol, $ptsPol);
                        $finalLbl  = spe_label_from_polarity($finalPol);
                        $lblFinal  = html_writer::tag('span', s($finalLbl), ['style' => $badge($finalLbl)]);
                        $finalScore = sprintf('%.3f', $finalPol);

                        // Excerpt
                        $excerpt = s(core_text::substr(clean_text((string)$r->text), 0, 120)) .
                                   (core_text::strlen((string)$r->text) > 120 ? '…' : '');

                        // Assemble row
                        $rowshtml .= html_writer::tag('tr',
                            html_writer::tag('td', $rname) .
                            html_writer::tag('td', $tname) .
                            html_writer::tag('td', s($r->type)) .
                            html_writer::tag('td', $lblText) .
                            html_writer::tag('td', $textScore) .
                            html_writer::tag('td', $sumptsDisplay) .
                            html_writer::tag('td', $lblFinal . ' ' . $finalScore) .
                            html_writer::tag('td', $excerpt)
                        );
                    }

                    // Modal
                    echo '
<div class="modal fade show" id="speAnalysisModal" tabindex="-1" role="dialog" style="display:block;background:rgba(0,0,0,0.35);" aria-modal="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="border-radius:12px;">
      <div class="modal-header">
        <h5 class="modal-title">Sentiment analysis of your submission</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="document.getElementById(\'speAnalysisModal\').remove();"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" style="max-height:60vh;overflow:auto;">
        <table class="generaltable">
          <thead>
            <tr>
              <th>Rater</th>
              <th>Target</th>
              <th>Type</th>
              <th>Text Label</th>
              <th>Text Score</th>
              <th>Pts</th>
              <th>Final</th>
              <th>Excerpt</th>
            </tr>
          </thead>
          <tbody>'.$rowshtml.'</tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" onclick="document.getElementById(\'speAnalysisModal\').remove();">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener("keydown",function(e){
  if(e.key==="Escape"){
    var el=document.getElementById("speAnalysisModal");
    if(el){ el.remove(); }
  }
});
</script>';

                } else {
                    echo $OUTPUT->notification('Analysis failed or invalid response from Sentiment API.', 'notifyproblem');
                }
            } catch (Exception $e) {
                echo $OUTPUT->notification('Error contacting Sentiment API: ' . s($e->getMessage()), 'notifyproblem');
            }
        } else {
            echo $OUTPUT->notification('Sentiment API URL not configured in plugin settings.', 'notifyproblem');
        }
    }
}

// ---------------------------------------------------------------------
// 7) Footer
// ---------------------------------------------------------------------
echo $OUTPUT->footer();
