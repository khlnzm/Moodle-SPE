<?php
// ============================================================================
// SPE (Self & Peer Evaluation) - view.php
// - Renders Self + Peer evaluation form
// - Validates reflection (>=100 words)
// - Saves to spe_submission / spe_rating
// - Queues texts into spe_sentiment (for later batch NLP)
// - Draft autosave/restore via user preferences (mod/spe/draft.php)
// - Prevent double submit (redirect to submission summary)
// - LIVE SENTIMENT: real-time sentiment + word count under ALL comment boxes
// - DISPARITY BANNERS: inline per section + final global notice (no explanation box)
// - Records disparity decisions into spe_disparity for reports
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

// Live sentiment API endpoint (FastAPI endpoint: /analyze).
$SPE_LIVE_SENTIMENT_API = get_config('mod_spe', 'sentiment_live_url') ?: 'http://localhost:8000/analyze';

// Instructor dashboard button (only for teachers/managers).
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
// Guard: prevent double-submit
// ---------------------------------------------------------------------
$existing = $DB->get_record('spe_submission', [
    'speid'  => $cm->instance,
    'userid' => $USER->id
], '*', IGNORE_MISSING);

if ($existing) {
    $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
    redirect($submissionurl, get_string('alreadysubmitted', 'spe'), 2);
    exit;
}

// ---------------------------------------------------------------------
// Header & intro
// ---------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Self and Peer Evaluation');

// Instructions
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

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/** Unicode-safe word count (for min-words check). */
function spe_wordcount(string $text): int {
    if (preg_match_all("/[\\p{L}\\p{N}’']+/u", $text, $m)) {
        return count($m[0]);
    }
    return 0;
}

// Score bounds
const SPE_SCORE_MIN = 5;   // 5 criteria * 1
const SPE_SCORE_MAX = 25;  // 5 criteria * 5

// Criteria
$criteria = [
    'effortdocs'    => 'The amount of work and effort put into the Requirements/Analysis Document, the Project Management Plan, and the Design Document.',
    'teamwork'      => 'Willingness to work as part of the group and taking responsibility.',
    'communication' => 'Communication within the group and participation in meetings.',
    'management'    => 'Contribution to management, e.g., work delivered on time.',
    'problemsolve'  => 'Problem solving and creativity for the group’s work.'
];

// ---------------------------------------------------------------------
// Resolve peers via groups
// ---------------------------------------------------------------------
$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id);
if (!empty($usergroups[0])) {
    $mygroupid = reset($usergroups[0]);
    $members   = groups_get_members($mygroupid, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if ((int)$u->id !== (int)$USER->id) {
            $peers[] = $u;
        }
    }
} else {
    echo $OUTPUT->notification('You are not in any group yet. Please ask your instructor to add you to a group.', 'notifyproblem');
}

// ---------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------
$submitted = optional_param('submitted', 0, PARAM_INT);

// Global disparity flag (set by JS if any section had a disparity)
$disparity_required = optional_param('disparity_required', 0, PARAM_BOOL);

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
        if (!empty($draftdata[$k]) && is_string($draftdata[$k])) {
            $prefill[$k] = $draftdata[$k];
        }
    }
    if (!empty($draftdata['selfscores']) && is_array($draftdata['selfscores'])) {
        $prefill['selfscores'] = array_merge($prefill['selfscores'], $draftdata['selfscores']);
    }
    if (!empty($draftdata['peerscores']) && is_array($draftdata['peerscores'])) {
        $prefill['peerscores'] = array_merge($prefill['peerscores'], $draftdata['peerscores']);
    }
    if (!empty($draftdata['peertexts']) && is_array($draftdata['peertexts'])) {
        $prefill['peertexts']  = array_merge($prefill['peertexts'], $draftdata['peertexts']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // Collect inputs
    $selfdesc   = trim(optional_param('selfdesc', '', PARAM_RAW));
    $reflection = trim(optional_param('reflection', '', PARAM_RAW));

    $selfscores = [];
    foreach ($criteria as $key => $label) {
        $selfscores[$key] = optional_param("self_{$key}", 0, PARAM_INT);
    }

    $peerscores = [];
    $peertexts  = [];
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

    // Validation
    $errors   = [];
    $refwords = spe_wordcount($reflection);
    if ($refwords < 100) {
        $errors[] = "Reflection must be at least 100 words (currently $refwords).";
    }

    if (!empty($errors)) {
        foreach ($errors as $e) {
            echo $OUTPUT->notification($e, 'notifyproblem');
        }
        $submitted = 0;
    } else {
        // Save — final guard against double insert
        if ($DB->record_exists('spe_submission', ['speid' => $cm->instance, 'userid' => $USER->id])) {
            $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
            redirect($submissionurl, get_string('alreadysubmitted', 'mod_spe'), 2);
            exit;
        }

        $DB->insert_record('spe_submission', (object)[
            'speid'        => $cm->instance,
            'userid'       => $USER->id,
            'selfdesc'     => $selfdesc,
            'reflection'   => $reflection,
            'wordcount'    => $refwords,
            'timecreated'  => time(),
            'timemodified' => time()
        ]);

        // Reset any prior ratings by this rater
        $DB->delete_records('spe_rating', ['speid' => $cm->instance, 'raterid' => $USER->id]);

        // Insert self scores (store selfdesc as comment on each criterion)
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

            // Queue peer comment for NLP (optional background analysis)
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

        // Queue reflection for NLP
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

        // ----- Record disparity decisions (self + each peer) -----
        $upsert_disparity = function(int $speid, int $raterid, int $rateeid, string $label = '', int $total = 0) use ($DB) {
            $existing = $DB->get_record('spe_disparity', [
                'speid'   => $speid,
                'raterid' => $raterid,
                'rateeid' => $rateeid
            ]);
            $rec = (object)[
                'speid'       => $speid,
                'raterid'     => $raterid,
                'rateeid'     => $rateeid,
                'label'       => $label,
                'scoretotal'  => $total,
                'timecreated' => time()
            ];
            if ($existing) {
                $rec->id = $existing->id;
                $DB->update_record('spe_disparity', $rec);
            } else {
                $DB->insert_record('spe_disparity', $rec);
            }
        };

        // Self disparity from hidden inputs set by JS
        $disparity_self_flag  = optional_param('disparity_self', 0, PARAM_BOOL);
        $disparity_self_label = optional_param('disparity_self_label', '', PARAM_TEXT);
        $disparity_self_total = optional_param('disparity_self_total', 0, PARAM_INT);

        if (!empty($disparity_self_flag)) {
            $upsert_disparity($cm->instance, $USER->id, $USER->id, $disparity_self_label, (int)$disparity_self_total);
        } else {
            $DB->delete_records('spe_disparity', ['speid' => $cm->instance, 'raterid' => $USER->id, 'rateeid' => $USER->id]);
        }

        // Peer disparities (loop peers and read their hidden flags)
        foreach ($peers as $p) {
            $flag  = optional_param("disparity_peer_{$p->id}", 0, PARAM_BOOL);
            $label = optional_param("disparity_peer_label_{$p->id}", '', PARAM_TEXT);
            $total = optional_param("disparity_peer_total_{$p->id}", 0, PARAM_INT);

            if (!empty($flag)) {
                $upsert_disparity($cm->instance, $USER->id, (int)$p->id, $label, (int)$total);
            } else {
                $DB->delete_records('spe_disparity', ['speid' => $cm->instance, 'raterid' => $USER->id, 'rateeid' => $p->id]);
            }
        }

        // Success — clear draft & redirect
        unset_user_preference($draftkey, $USER);
        $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
        redirect($submissionurl, 'Your submission has been saved successfully!', 2);
        exit;
    }
}

// ---------------------------------------------------------------------
// Render form (prefilled if needed / after draft merge)
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

    // Hidden (self disparity)
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'disparity_self',
        'id'    => 'disparity_self',
        'value' => '0'
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'disparity_self_label',
        'id'    => 'disparity_self_label',
        'value' => ''
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'disparity_self_total',
        'id'    => 'disparity_self_total',
        'value' => '0'
    ]);

    // Self description (LIVE) + section disparity banner
    echo html_writer::tag('h4', 'Briefly describe how you believe you contributed to the project process:');
    echo html_writer::start_div('spe-livewrap', ['data-live' => '1']);
    echo html_writer::tag('textarea', $prefill['selfdesc'] ?? '', [
        'name'  => 'selfdesc',
        'rows'  => 4,
        'cols'  => 80,
        'class' => 'spe-live-textarea'
    ]);
    echo html_writer::div(
        '<span class="spe-live-badge neutral">NEUTRAL</span> ' .
        '<span class="spe-live-wc">Words: 0</span>',
        'spe-live-hud'
    );
    echo html_writer::end_div();
    echo html_writer::div('Score/comment mismatch for your self evaluation.', 'spe-disp', [
        'id'    => 'disp-self',
        'style' => 'display:none'
    ]);

    // Reflection (LIVE) + section disparity banner
    echo html_writer::tag('h4', 'Reflection (minimum 100 words)');
    echo html_writer::start_div('spe-livewrap', ['data-live' => '1']);
    echo html_writer::tag('textarea', $prefill['reflection'] ?? '', [
        'name'  => 'reflection',
        'rows'  => 6,
        'cols'  => 80,
        'class' => 'spe-live-textarea'
    ]);
    echo html_writer::div(
        '<span class="spe-live-badge neutral">NEUTRAL</span> ' .
        '<span class="spe-live-wc">Words: 0</span>',
        'spe-live-hud'
    );
    echo html_writer::end_div();
    echo html_writer::div('Score/comment mismatch for your self evaluation.', 'spe-disp', [
        'id'    => 'disp-self-ref',
        'style' => 'display:none'
    ]);

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

            // Hidden disparity inputs for this peer
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => "disparity_peer_{$p->id}",
                'id'    => "disparity_peer_{$p->id}",
                'value' => '0'
            ]);
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => "disparity_peer_label_{$p->id}",
                'id'    => "disparity_peer_label_{$p->id}",
                'value' => ''
            ]);
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => "disparity_peer_total_{$p->id}",
                'id'    => "disparity_peer_total_{$p->id}",
                'value' => '0'
            ]);

            // Peer comment (LIVE) + per-member disparity banner
            echo html_writer::tag('p', 'Briefly describe how you believe this person contributed to the project process:');
            echo html_writer::start_div('spe-livewrap', ['data-live' => '1']);
            echo html_writer::tag('textarea', $ptext[$p->id] ?? '', [
                'name'  => "comment_{$p->id}",
                'rows'  => 4,
                'cols'  => 80,
                'class' => 'spe-live-textarea'
            ]);
            echo html_writer::div(
                '<span class="spe-live-badge neutral">NEUTRAL</span> ' .
                '<span class="spe-live-wc">Words: 0</span>',
                'spe-live-hud'
            );
            echo html_writer::end_div();

            echo html_writer::div('Score/comment mismatch for this member.', 'spe-disp', [
                'id'    => "disp-peer-{$p->id}",
                'style' => 'display:none'
            ]);

            echo html_writer::empty_tag('hr');
        }
    } else {
        echo $OUTPUT->notification('No peers found in your group. You can still submit your self-evaluation.', 'notifywarning');
    }

    // --- Final/global disparity banner (simple message) ---
    echo html_writer::start_div('', [
        'id'    => 'global-disparity-wrap',
        'style' => 'display:none;margin:16px 0;padding:12px;border:1px solid #f0c36d;background:#fff8e1;border-radius:6px;'
    ]);
    echo html_writer::tag('div', 'There is a disparity. Do you want to continue with the submission?', [
        'style' => 'font-weight:600;color:#8a6d3b;'
    ]);
    echo html_writer::end_div();

    // Hidden flag the JS will set if any disparity is detected client-side
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'disparity_required',
        'id'    => 'disparity_required',
        'value' => '0'
    ]);

    // Submit button
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Submit']);
    echo html_writer::end_tag('form');

    // --------- Styles ----------
    echo '<style>
        .spe-livewrap { margin-bottom: 8px; }
        .spe-live-hud { margin-top: 6px; display: flex; gap: 10px; align-items: center; }
        .spe-live-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:12px; color:#fff; background:#6b7280; }
        .spe-live-badge.positive { background:#16a34a; }
        .spe-live-badge.neutral  { background:#6b7280; }
        .spe-live-badge.negative { background:#dc2626; }
        .spe-live-badge.toxic    { background:#111827; }
        .spe-live-wc { font-size:12px; color:#6b7280; }
        .spe-disp { margin:8px 0 14px; padding:10px 12px; border:1px solid #f0c36d; background:#fff8e1; color:#8a6d3b; border-radius:6px; }
    </style>';

    // ---------- AUTOSAVE DRAFT (JS) ----------
    ?>
    <script>
    (function () {
        const form = document.querySelector('form[action*="/mod/spe/view.php"]');
        if (!form) return;

        const cmid       = <?php echo (int)$cm->id; ?>;
        const sesskeyVal = "<?php echo sesskey(); ?>";
        const draftUrl   = M.cfg.wwwroot + "/mod/spe/draft.php?id=" + cmid + "&sesskey=" + encodeURIComponent(sesskeyVal);

        function readFormJSON() {
            const data = {
                selfdesc:   form.querySelector('[name="selfdesc"]')?.value || '',
                reflection: form.querySelector('[name="reflection"]')?.value || '',
                selfscores: {},
                peerscores: {},
                peertexts:  {}
            };

            form.querySelectorAll('select[name^="self_"]').forEach(sel => {
                const key = sel.name.replace(/^self_/, '');
                data.selfscores[key] = sel.value ? parseInt(sel.value, 10) : 0;
            });

            form.querySelectorAll('select[name^="peer_"]').forEach(sel => {
                const parts = sel.name.split('_');
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
                    for (const k in d.peertexts) d.peertexts[k] = (d.peertexts[k] || '').slice(0, 15000);
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

        (async function restoreDraft() {
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
            } catch(e) {}
        })();
    })();
    </script>

    <!-- LIVE SENTIMENT + PER-SECTION & GLOBAL DISPARITY -->
    <script>
    (function () {
        const LIVE_API = <?php echo json_encode($SPE_LIVE_SENTIMENT_API); ?>;

        function debounce(fn, wait){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), wait); }; }
        function applyBadge(el, label){
            const cls = (label || 'neutral').toLowerCase();
            el.textContent = cls.toUpperCase();
            el.classList.remove('positive','neutral','negative','toxic');
            el.classList.add(cls);
        }

        const activeDisparities = new Set();
        const globalWrap = document.getElementById('global-disparity-wrap');
        const globalFlag = document.getElementById('disparity_required');

        const selfFlag   = document.getElementById('disparity_self');
        const selfLabel  = document.getElementById('disparity_self_label');
        const selfTotal  = document.getElementById('disparity_self_total');

        function refreshGlobalBanner() {
            if (activeDisparities.size > 0) {
                globalWrap.style.display = '';
                globalFlag.value = '1';
            } else {
                globalWrap.style.display = 'none';
                globalFlag.value = '0';
            }
        }

        // current totals
        function currentSelfTotal() {
            let total = 0;
            document.querySelectorAll('select[name^="self_"]').forEach(sel => {
                const v = parseInt(sel.value, 10);
                if (!Number.isNaN(v)) total += v;
            });
            return total;
        }
        function currentPeerTotal(peerId) {
            let total = 0;
            document.querySelectorAll(`select[name^="peer_${peerId}_"]`).forEach(sel => {
                const v = parseInt(sel.value, 10);
                if (!Number.isNaN(v)) total += v;
            });
            return total;
        }

        const wrappers = document.querySelectorAll('.spe-livewrap[data-live="1"]');
        const updates  = [];

        wrappers.forEach(wrap => {
            const ta    = wrap.querySelector('textarea.spe-live-textarea');
            const badge = wrap.querySelector('.spe-live-badge');
            const wc    = wrap.querySelector('.spe-live-wc');
            if (!ta || !badge || !wc) return;

            // context (self/reflection vs peer)
            let ctx = { kind:'self', id:'self' };
            if (ta.name && ta.name.startsWith('comment_')) {
                ctx = { kind:'peer', id: ta.name.split('_')[1] };
            } else if (ta.name === 'reflection') {
                ctx = { kind:'self-ref', id:'self-ref' };
            }

            const sectionDispId = (ctx.kind === 'peer') ? `disp-peer-${ctx.id}` :
                                  (ta.name === 'reflection' ? 'disp-self-ref' : 'disp-self');
            const sectionDispEl = document.getElementById(sectionDispId);

            // For peer hidden inputs
            const peerFlag  = (ctx.kind === 'peer') ? document.getElementById(`disparity_peer_${ctx.id}`) : null;
            const peerLabel = (ctx.kind === 'peer') ? document.getElementById(`disparity_peer_label_${ctx.id}`) : null;
            const peerTotal = (ctx.kind === 'peer') ? document.getElementById(`disparity_peer_total_${ctx.id}`) : null;

            const update = debounce(async () => {
                const text = ta.value || "";
                wc.textContent = "Words: " + (text.trim().split(/\s+/).filter(Boolean).length || 0);

                const score_total = (ctx.kind === 'peer') ? currentPeerTotal(ctx.id) : currentSelfTotal();

                try {
                    const res = await fetch(LIVE_API, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            text,
                            score_total,
                            score_min: <?php echo (int)SPE_SCORE_MIN; ?>,
                            score_max: <?php echo (int)SPE_SCORE_MAX; ?>
                        })
                    });
                    const data = await res.json();

                    applyBadge(badge, data.label || "neutral");
                    wc.textContent = "Words: " + (data.word_count ?? 0);

                    const key = `${ctx.kind}:${ctx.id}`;
                    if (data.disparity === true) {
                        if (sectionDispEl) sectionDispEl.style.display = '';
                        activeDisparities.add(key);
                    } else {
                        if (sectionDispEl) sectionDispEl.style.display = 'none';
                        activeDisparities.delete(key);
                    }

                    // Write hidden inputs (self or peer)
                    if (ctx.kind === 'peer') {
                        if (peerFlag)  peerFlag.value  = (data.disparity === true) ? '1' : '0';
                        if (peerLabel) peerLabel.value = (data.label || '');
                        if (peerTotal) peerTotal.value = String(score_total || 0);
                    } else { // self or self-ref both roll up into the self hidden set
                        if (selfFlag)  selfFlag.value  = (activeDisparities.has('self:self') || activeDisparities.has('self-ref:self-ref')) ? '1' : (data.disparity === true ? '1' : '0');
                        if (selfLabel) selfLabel.value = (data.label || '');
                        if (selfTotal) selfTotal.value = String(currentSelfTotal() || 0);
                    }

                    refreshGlobalBanner();
                } catch {
                    applyBadge(badge, "neutral");
                }
            }, 250);

            ta.addEventListener('input', update);
            wrap._update = update;
            updates.push(update);
        });

        // Recompute when any select changes (self or peer)
        function updateAll(){ updates.forEach(fn => fn()); }
        document.querySelectorAll('select[name^="self_"], select[name^="peer_"]').forEach(sel => {
            sel.addEventListener('change', updateAll);
            sel.addEventListener('input',  updateAll);
        });

        // initial pass
        updateAll();
    })();
    </script>
    <?php
    // ---------- END LIVE SENTIMENT + DISPARITY ----------
}

// Footer
echo $OUTPUT->footer();
