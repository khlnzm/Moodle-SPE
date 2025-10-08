<?php
require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/spe/view.php', ['id' => $cm->id]);
$PAGE->set_title('Self and Peer Evaluation');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Self and Peer Evaluation');

/** ------------------------------------------------------------------
 * Quick heuristic sentiment (immediate) for consistency gating.
 * Returns an integer score: >0 positive, <0 negative, 0 neutral.
 * ------------------------------------------------------------------ */
function spe_quick_sentiment(string $text): int {
    $text = core_text::strtolower($text);

    // Basic keyword lexicon (tweak anytime).
    $pos = ['good','great','excellent','helpful','reliable','on time','timely','positive','clear','creative','well done','organised','responsible','supportive'];
    $neg = ['poor','bad','late','lazy','unresponsive','rude','toxic','negative','problem','issue','did not','lacking','absent','missing','unprofessional','conflict'];

    $p = 0; $n = 0;
    foreach ($pos as $w) { if (strpos($text, $w) !== false) $p++; }
    foreach ($neg as $w) { if (strpos($text, $w) !== false) $n++; }

    return $p - $n; // >0 positive, <0 negative, 0 neutral
}

// ---------------- Instructions ----------------
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

// ---------------- Criteria ----------------
$criteria = [
    'effortdocs'    => 'The amount of work and effort put into the Requirements and Analysis Document, the Project Management Plan, and the Design Document.',
    'teamwork'      => 'Willingness to work as part of the group and taking responsibility in the group.',
    'communication' => 'Communication within the group and participation in group meetings.',
    'management'    => 'Contribution to the management of the project, e.g. work delivered on time.',
    'problemsolve'  => 'Problem solving and creativity on behalf of the group’s work.'
];

// ---------------- Peers via Moodle Groups ----------------
global $USER, $DB;

$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id);
if (!empty($usergroups[0])) {
    $mygroupid = reset($usergroups[0]);
    $members = groups_get_members($mygroupid, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if ((int)$u->id !== (int)$USER->id) { $peers[] = $u; }
    }
} else {
    echo $OUTPUT->notification('You are not in any group yet. Please ask your instructor to add you to a group.', 'notifyproblem');
}

// ---------------- Handle submission ----------------
$submitted           = optional_param('submitted', 0, PARAM_INT);
$confirmconsistency  = optional_param('confirmconsistency', 0, PARAM_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // Collect text + scores from POST (do this once so we can reuse in checks)
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

    // ---------------- Consistency detector ----------------
    // thresholds
    $HIGH = 4.0;   // avg >= 4 considered "high"
    $LOW  = 2.5;   // avg <= 2.5 considered "low"
    $mismatches = [];

    // helper to average
    $avg = function(array $arr): float {
        $vals = array_filter($arr, fn($v) => is_numeric($v) && $v > 0);
        return count($vals) ? array_sum($vals)/count($vals) : 0.0;
    };

    // Self: scores vs reflection + selfdesc combined
    $selfavg = $avg($selfscores);
    $selfsent = spe_quick_sentiment($selfdesc . ' ' . $reflection);
    if ($selfavg >= $HIGH && $selfsent < 0) {
        $mismatches[] = 'Your self scores are very high, but your self-description/reflection reads as negative.';
    }
    if ($selfavg <= $LOW && $selfsent > 0) {
        $mismatches[] = 'Your self scores are very low, but your self-description/reflection reads as positive.';
    }

    // Each peer: avg scores vs comment sentiment
    foreach ($peers as $p) {
        $pavg = $avg($peerscores[$p->id] ?? []);
        $psent = spe_quick_sentiment($peertexts[$p->id] ?? '');
        if ($pavg >= $HIGH && $psent < 0) {
            $mismatches[] = 'High scores given for ' . fullname($p) . ' but the comment is negative.';
        }
        if ($pavg <= $LOW && $psent > 0) {
            $mismatches[] = 'Low scores given for ' . fullname($p) . ' but the comment is positive.';
        }
    }

    // If mismatches and not yet confirmed, block and ask for review/confirmation
    if (!empty($mismatches) && !$confirmconsistency) {
        echo $OUTPUT->notification('Potential inconsistencies detected. Please review your scores or comments:', 'notifyproblem');
        echo html_writer::start_tag('ul');
        foreach ($mismatches as $m) {
            echo html_writer::tag('li', s($m));
        }
        echo html_writer::end_tag('ul');

        // Show a minimal confirm form to resubmit anyway
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
            'style'  => 'margin-top:12px'
        ]);
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'submitted','value'=>1]);
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'confirmconsistency','value'=>1]);

        // Re-embed minimal state so the student can continue without retyping big texts
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

        // Also render a link to go back to the form naturally
        echo html_writer::tag('p', html_writer::link(new moodle_url('/mod/spe/view.php', ['id'=>$cm->id]), 'Go back and edit your answers.'));
        echo $OUTPUT->footer();
        exit;
    }

    // ---------------- Save (passed consistency or user confirmed) ----------------

    // Save submission (selfdesc + reflection)
    $sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $USER->id]);
    $data = [
        'speid'        => $cm->instance,
        'userid'       => $USER->id,
        'selfdesc'     => $selfdesc,
        'reflection'   => $reflection,
        'wordcount'    => str_word_count(strip_tags($reflection)),
        'timemodified' => time()
    ];
    if ($sub) {
        $data['id'] = $sub->id;
        $DB->update_record('spe_submission', (object)$data);
    } else {
        $data['timecreated'] = time();
        $DB->insert_record('spe_submission', (object)$data);
    }

    // Replace rater's ratings for this SPE
    $DB->delete_records('spe_rating', ['speid' => $cm->instance, 'raterid' => $USER->id]);

    // Self scores (store selfdesc as the comment)
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

        // Optional: queue peer comment for later NLP
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
    // Optional: queue reflection too
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

// ---------------- Form render ----------------
if (!$submitted) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
    ]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'submitted','value'=>1]);

    // Self
    echo html_writer::tag('h3', 'Self Evaluation');
    foreach ($criteria as $key => $label) {
        echo html_writer::tag('p', $label);
        echo html_writer::start_tag('select', ['name'=>"self_{$key}", 'required'=>'required']);
        echo '<option value="">--</option>';
        for ($i=1; $i<=5; $i++) echo "<option value=\"$i\">$i</option>";
        echo html_writer::end_tag('select');
        echo html_writer::empty_tag('br');
    }

    echo html_writer::tag('h4', 'Briefly describe how you believe you contributed to the project process:');
    echo html_writer::tag('textarea', '', ['name'=>'selfdesc','rows'=>4,'cols'=>80]);

    echo html_writer::tag('h4', 'Reflection (minimum 100 words)');
    echo html_writer::tag('textarea', '', ['name'=>'reflection','rows'=>6,'cols'=>80]);

    // Peers
    if (!empty($peers)) {
        echo html_writer::tag('h3', 'Evaluation of Team Members');
        foreach ($peers as $p) {
            echo html_writer::tag('h4', 'Member: ' . fullname($p) . " ({$p->username})");
            foreach ($criteria as $key => $label) {
                echo html_writer::tag('p', $label);
                echo html_writer::start_tag('select', ['name'=>"peer_{$p->id}_{$key}", 'required'=>'required']);
                echo '<option value="">--</option>';
                for ($i=1; $i<=5; $i++) echo "<option value=\"$i\">$i</option>";
                echo html_writer::end_tag('select');
                echo html_writer::empty_tag('br');
            }
            echo html_writer::tag('p', 'Briefly describe how you believe this person contributed to the project process:');
            echo html_writer::tag('textarea', '', ['name'=>"comment_{$p->id}", 'rows'=>4,'cols'=>80]);
            echo html_writer::empty_tag('hr');
        }
    } else {
        echo $OUTPUT->notification('No peers found in your group. You can still submit your self-evaluation.', 'notifywarning');
    }

    echo html_writer::empty_tag('input', ['type'=>'submit','value'=>'Submit']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
