<?php
// ============================================================================
// SPE (Self & Peer Evaluation) - view.php
// - Renders "SPE1" form (Self + Peer evaluation)
// - Validates reflection (>=100 words)
// - Detects mismatches between numeric scores and comment sentiment
// - Saves to spe_submission / spe_rating
// - Optionally queues text to spe_sentiment (if table exists) for later NLP
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
 * Quick heuristic sentiment (offline, immediate).
 * Returns an integer: >0 positive, <0 negative, 0 neutral.
 * This is used ONLY for the "consistency" warning gate.
 * You'll likely replace/augment with a real NLP later.
 */
function spe_quick_sentiment(string $text): int {
    $text = core_text::strtolower($text);

    // Simple word lists (tune anytime).
    $pos = ['good','great','excellent','helpful','reliable','on time','timely','positive','clear','creative','well done','organised','responsible','supportive'];
    $neg = ['poor','bad','late','lazy','unresponsive','rude','toxic','negative','problem','issue','did not','lacking','absent','missing','unprofessional','conflict'];

    $p = 0; $n = 0;
    foreach ($pos as $w) { if (strpos($text, $w) !== false) $p++; }
    foreach ($neg as $w) { if (strpos($text, $w) !== false) $n++; }
    return $p - $n;
}

/**
 * Unicode-safe word count (for Reflection min-words check).
 */
function spe_wordcount(string $text): int {
    if (preg_match_all("/[\\p{L}\\p{N}’']+/u", $text, $m)) {
        return count($m[0]);
    }
    return 0;
}

// ---------------------------------------------------------------------
// 3) Static instructions & criteria (from your SPE1 document)
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
//    (No custom CSV needed—uses course groups as teams.)
// ---------------------------------------------------------------------
global $USER, $DB;

$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id); // [0] => array of groupids
if (!empty($usergroups[0])) {
    $mygroupid = reset($usergroups[0]);
    $members = groups_get_members($mygroupid, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if ((int)$u->id !== (int)$USER->id) { $peers[] = $u; } // exclude self
    }
} else {
    echo $OUTPUT->notification('You are not in any group yet. Please ask your instructor to add you to a group.', 'notifyproblem');
}

// ---------------------------------------------------------------------
// 5) Handle POST (validation, consistency-gate, save, queue)
// ---------------------------------------------------------------------
$submitted          = optional_param('submitted', 0, PARAM_INT);
$confirmconsistency = optional_param('confirmconsistency', 0, PARAM_INT);

// These vars will be used to prefill the form if validation fails.
$prefill = [
    'selfdesc'   => '',
    'reflection' => '',
    'selfscores' => [],
    'peerscores' => [],
    'peertexts'  => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // ---- 5.1 Collect inputs once (to reuse in checks + saving) ----
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

    // ---- 5.2 Validation: Reflection min 100 words (server-side) ----
    $errors = [];
    $refwords = spe_wordcount($reflection);
    if ($refwords < 100) {
        $errors[] = "Reflection must be at least 100 words (currently $refwords).";
    }

    // (Optional) You can also enforce that ALL selects are chosen:
    // foreach ($criteria as $key => $label) {
    //     if (empty($selfscores[$key])) { $errors[] = "Select a self score for: $label"; }
    // }
    // foreach ($peers as $p) {
    //     foreach ($criteria as $key => $label) {
    //         if (empty($peerscores[$p->id][$key])) {
    //             $errors[] = "Select a score for ".fullname($p)." on: $label";
    //         }
    //     }
    // }

    if (!empty($errors)) {
        // Show errors and render the form again (no DB writes).
        foreach ($errors as $e) {
            echo $OUTPUT->notification($e, 'notifyproblem');
        }
        $submitted = 0; // force form to render
    } else {
        // ---- 5.3 Consistency detection: compare averages vs sentiment ----
        $HIGH = 4.0;  // avg >= 4 = high
        $LOW  = 2.5;  // avg <= 2.5 = low
        $mismatches = [];

        $avg = function(array $arr): float {
            $vals = array_filter($arr, fn($v) => is_numeric($v) && $v > 0);
            return count($vals) ? array_sum($vals)/count($vals) : 0.0;
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

        // If mismatches exist and not yet confirmed: show a blocking warning.
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
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'submitted','value'=>1]);
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'confirmconsistency','value'=>1]);

            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'selfdesc','value'=>$selfdesc]);
            echo html_writer::tag('textarea', $reflection, ['name'=>'reflection','style'=>'display:none']);

            foreach ($criteria as $key => $label) {
                echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>"self_{$key}", 'value'=>$selfscores[$key]]);
            }
            foreach ($peers as $p) {
                echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>"comment_{$p->id}", 'value'=>$peertexts[$p->id]]);
                foreach ($criteria as $key => $label) {
                    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>"peer_{$p->id}_{$key}", 'value'=>$peerscores[$p->id][$key]]);
                }
            }
            echo html_writer::empty_tag('input', ['type'=>'submit','value'=>'Submit anyway (I confirm this is intended)']);
            echo html_writer::end_tag('form');

            // Option to go back to the form
            echo html_writer::tag('p', html_writer::link(new moodle_url('/mod/spe/view.php', ['id'=>$cm->id]), 'Go back and edit your answers.'));
            echo $OUTPUT->footer();
            exit; // Stop now. Save only happens after confirmation.
        }

        // ---- 5.4 Save (valid + either consistent or user confirmed) ----

        // Save submission (selfdesc + reflection)
        $sub = $DB->get_record('spe_submission', ['speid'=>$cm->instance, 'userid'=>$USER->id]);
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
        $DB->delete_records('spe_rating', ['speid'=>$cm->instance, 'raterid'=>$USER->id]);

        // Self scores (store selfdesc as the "comment")
        foreach ($criteria as $key => $label) {
            $score = $selfscores[$key] ?? 0;
            if ($score >= 1 && $score <= 5) {
                $DB->insert_record('spe_rating', (object)[
                    'speid'      => $cm->instance,
                    'raterid'    => $USER->id,
                    'rateeid'    => $USER->id,
                    'criterion'  => $key,
                    'score'      => $score,
                    'comment'    => $selfdesc ?: null,
                    'timecreated'=> time()
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
                        'speid'      => $cm->instance,
                        'raterid'    => $USER->id,
                        'rateeid'    => $p->id,
                        'criterion'  => $key,
                        'score'      => $score,
                        'comment'    => $peercomment ?: null,
                        'timecreated'=> time()
                    ]);
                }
            }
            // Optional: queue peer comment for later NLP if table exists
            if ($DB->get_manager()->table_exists('spe_sentiment') && $peercomment !== '') {
                $DB->insert_record('spe_sentiment', (object)[
                    'speid'      => $cm->instance,
                    'raterid'    => $USER->id,
                    'rateeid'    => $p->id,
                    'type'       => 'peer_comment',
                    'text'       => $peercomment,
                    'status'     => 'pending',
                    'timecreated'=> time()
                ]);
            }
        }

        // Optional: queue reflection as well
        if ($DB->get_manager()->table_exists('spe_sentiment') && $reflection !== '') {
            $DB->insert_record('spe_sentiment', (object)[
                'speid'      => $cm->instance,
                'raterid'    => $USER->id,
                'rateeid'    => $USER->id,
                'type'       => 'reflection',
                'text'       => $reflection,
                'status'     => 'pending',
                'timecreated'=> time()
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
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'submitted','value'=>1]);

    // Self Evaluation
    echo html_writer::tag('h3', 'Self Evaluation');
    $sel = $prefill['selfscores'] ?? [];
    foreach ($criteria as $key => $label) {
        echo html_writer::tag('p', $label);
        echo '<select name="self_' . $key . '" required>';
        echo '<option value="">--</option>';
        for ($i=1; $i<=5; $i++) {
            $selected = (isset($sel[$key]) && (int)$sel[$key] === $i) ? ' selected' : '';
            echo "<option value=\"$i\"$selected>$i</option>";
        }
        echo '</select><br>';
    }

    echo html_writer::tag('h4', 'Briefly describe how you believe you contributed to the project process:');
    echo html_writer::tag('textarea', $prefill['selfdesc'] ?? '', ['name'=>'selfdesc','rows'=>4,'cols'=>80]);

    echo html_writer::tag('h4', 'Reflection (minimum 100 words)');
    echo html_writer::tag('textarea', $prefill['reflection'] ?? '', ['name'=>'reflection','rows'=>6,'cols'=>80]);

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
                for ($i=1; $i<=5; $i++) {
                    $selected = (isset($psel[$p->id][$key]) && (int)$psel[$p->id][$key] === $i) ? ' selected' : '';
                    echo "<option value=\"$i\"$selected>$i</option>";
                }
                echo '</select><br>';
            }
            echo html_writer::tag('p', 'Briefly describe how you believe this person contributed to the project process:');
            echo html_writer::tag('textarea', $ptext[$p->id] ?? '', ['name'=>"comment_{$p->id}", 'rows'=>4, 'cols'=>80]);
            echo html_writer::empty_tag('hr');
        }
    } else {
        echo $OUTPUT->notification('No peers found in your group. You can still submit your self-evaluation.', 'notifywarning');
    }

    echo html_writer::empty_tag('input', ['type'=>'submit','value'=>'Submit']);
    echo html_writer::end_tag('form');
}

// ---------------------------------------------------------------------
// 7) Footer
// ---------------------------------------------------------------------
echo $OUTPUT->footer();
