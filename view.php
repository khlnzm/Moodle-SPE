<?php
require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/spe/view.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('pluginname', 'mod_spe'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'mod_spe'));

// Display instructions
echo html_writer::tag('div', '
    <p><strong>Please note:</strong> Everything that you put into this form will be kept strictly confidential by the unit coordinator.</p>

    <h4>Using the assessment scales</h4>
    <p>The scales are from 1 to 5:</p>
    <ul>
        <li>1 = Very poor, or even obstructive, contribution to the project process</li>
        <li>2 = Poor contribution to the project process</li>
        <li>3 = Acceptable contribution to the project process</li>
        <li>4 = Good contribution to the project process</li>
        <li>5 = Excellent contribution to the project process</li>
    </ul>

    <h4>The assessment criteria</h4>
    <ol>
        <li>The amount of work and effort put into the Requirements and Analysis Document, the Project Management Plan, and the Design Document.</li>
        <li>Willingness to work as part of the group and taking responsibility in the group.</li>
        <li>Communication within the group and participation in group meetings.</li>
        <li>Contribution to the management of the project, e.g. work delivered on time.</li>
        <li>Problem solving and creativity on behalf of the group’s work.</li>
    </ol>
', ['class' => 'spe-instructions']);


// ===== Find peers using Moodle Groups =====
global $USER;

$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id); // array: [0 => [groupids...]]
if (!empty($usergroups[0])) {
    $mygroupid = reset($usergroups[0]); // take first group for now
    $members = groups_get_members($mygroupid, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if ((int)$u->id !== (int)$USER->id) { $peers[] = $u; }
    }
} else {
    echo $OUTPUT->notification('You are not in any group yet. Please ask your instructor to add you to a group.', 'notifyproblem');
}

// ===== Handle submission =====
$submitted = optional_param('submitted', 0, PARAM_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $reflection = trim(optional_param('reflection', '', PARAM_RAW));
    $words = str_word_count(strip_tags($reflection));

    // Upsert into spe_submission
    $sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $USER->id]);
    if ($sub) {
        $sub->reflection   = $reflection;
        $sub->wordcount    = $words;
        $sub->timemodified = time();
        $DB->update_record('spe_submission', $sub);
    } else {
        $sub = (object)[
            'speid'        => $cm->instance,
            'userid'       => $USER->id,
            'reflection'   => $reflection,
            'wordcount'    => $words,
            'timecreated'  => time(),
            'timemodified' => time()
        ];
        $sub->id = $DB->insert_record('spe_submission', $sub);
    }

    // Replace my ratings for this SPE
    $DB->delete_records('spe_rating', ['speid' => $cm->instance, 'raterid' => $USER->id]);

    // Self scores
    foreach ($criteria as $key => $label) {
        $score = optional_param("self_{$key}", 0, PARAM_INT);
        if ($score >= 1 && $score <= 5) {
            $DB->insert_record('spe_rating', (object)[
                'speid' => $cm->instance,
                'raterid' => $USER->id,
                'rateeid' => $USER->id,
                'criterion' => $key,
                'score' => $score,
                'comment' => null,
                'timecreated' => time()
            ]);
        }
    }

    // Peer scores & one comment per peer
    foreach ($peers as $p) {
        $comment = optional_param("comment_{$p->id}", '', PARAM_RAW_TRIMMED);
        foreach ($criteria as $key => $label) {
            $score = optional_param("peer_{$p->id}_{$key}", 0, PARAM_INT);
            if ($score >= 1 && $score <= 5) {
                $DB->insert_record('spe_rating', (object)[
                    'speid' => $cm->instance,
                    'raterid' => $USER->id,
                    'rateeid' => $p->id,
                    'criterion' => $key,
                    'score' => $score,
                    'comment' => $comment ?: null,
                    'timecreated' => time()
                ]);
            }
        }
    }

    echo $OUTPUT->notification('Your submission has been saved.', 'notifysuccess');
    $submitted = 1;
}

// ===== Render form =====
if (!$submitted) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    // Self
    echo html_writer::tag('h4', 'Self Evaluation');
    foreach ($criteria as $key => $label) {
        echo html_writer::start_tag('p');
        echo html_writer::tag('label', $label . ': ');
        echo html_writer::start_tag('select', ['name' => "self_{$key}"]);
        echo '<option value="">--</option>';
        for ($i=1; $i<=5; $i++) { echo "<option value=\"$i\">$i</option>"; }
        echo html_writer::end_tag('select');
        echo html_writer::end_tag('p');
    }

    // Peers
    if (!empty($peers)) {
        echo html_writer::tag('h4', 'Peer Evaluation');
        foreach ($peers as $p) {
            echo html_writer::tag('h5', fullname($p) . " ({$p->username})");
            foreach ($criteria as $key => $label) {
                echo html_writer::start_tag('p');
                echo html_writer::tag('label', $label . ': ');
                echo html_writer::start_tag('select', ['name' => "peer_{$p->id}_{$key}"]);
                echo '<option value="">--</option>';
                for ($i=1; $i<=5; $i++) { echo "<option value=\"$i\">$i</option>"; }
                echo html_writer::end_tag('select');
                echo html_writer::end_tag('p');
            }
            echo html_writer::tag('p', html_writer::tag('label', 'Comment (optional): ')
                . html_writer::empty_tag('input', ['type' => 'text', 'name' => "comment_{$p->id}", 'size' => '60']));
            echo html_writer::empty_tag('hr');
        }
    }

    // Reflection
    echo html_writer::tag('h4', 'Reflection (min 100 words)');
    echo html_writer::tag('textarea', '', ['name' => 'reflection', 'rows' => 6, 'cols' => 80]);

    echo html_writer::empty_tag('br');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Submit']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
