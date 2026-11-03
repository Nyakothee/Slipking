<?php
/**
 * Job applications (people we hire to market and sell for the company).
 * The form lives on a hidden page (work-with-us.php): it is not linked anywhere on the site, it is not in the sitemap and search engines are told to stay away.
 * Only an admin can read the applications (Admin > Job applications). Uploaded documents (CV, photo, ID) are kept in private-uploads/jobs/,
 * which the web server never serves directly: an admin opens them through admin/job-file.php.
 */

function jobs_table_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT id FROM job_applications LIMIT 0'); $ok = true; } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function jobs_open(): bool { return setting('jobs_open', '1') === '1'; }

function job_statuses(): array
{
    return ['new' => 'New', 'reviewing' => 'Reviewing', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'hired' => 'Hired', 'rejected' => 'Not selected'];
}

/** The public link to the form. */
function jobs_link(): string { return url('work-with-us.php'); }

/**
 * The whole form, one place. Sections -> fields. type: text | textarea | select | radio | checks | date | url | email | tel | country | check
 * Keys marked core are stored in their own database column (for the admin list); everything else goes into the answers JSON.
 */
function job_form(): array
{
    $yn = ['Yes', 'No'];
    return [
        'position' => ['title' => '1. The job you want', 'fields' => [
            'role' => ['label' => 'Position you are applying for', 'type' => 'select', 'req' => 1, 'core' => 1, 'opts' => [
                'Marketing - Social media manager', 'Marketing - Paid ads specialist (Meta, Google, TikTok)', 'Marketing - Content creator / copywriter', 'Marketing - SEO specialist',
                'Marketing - Affiliate and partnerships manager', 'Marketing - Community manager (Telegram / WhatsApp)', 'Sales - Sales executive (subscriptions)',
                'Sales - Corporate and partnerships sales', 'Sales - Customer success and retention', 'Brand ambassador / influencer', 'Other (explain in section 4)']],
            'employment' => ['label' => 'Type of work you want', 'type' => 'select', 'req' => 1, 'opts' => ['Full-time', 'Part-time', 'Commission only', 'Freelance / contract', 'Internship']],
            'work_mode' => ['label' => 'Where will you work?', 'type' => 'select', 'req' => 1, 'opts' => ['Remote (from home)', 'On-site at the office', 'Hybrid (both)']],
            'start' => ['label' => 'When can you start?', 'type' => 'select', 'req' => 1, 'opts' => ['Immediately', 'Within 2 weeks', 'Within 1 month', 'More than 1 month']],
            'salary' => ['label' => 'Expected monthly pay (amount and currency)', 'type' => 'text', 'req' => 1, 'max' => 60, 'hint' => 'For example: KES 60,000 or USD 500.'],
            'commission_ok' => ['label' => 'How do you want to be paid?', 'type' => 'select', 'req' => 1, 'opts' => ['Fixed salary only', 'Salary plus commission', 'Commission only is fine']],
            'source' => ['label' => 'How did you hear about this job?', 'type' => 'select', 'req' => 0, 'opts' => ['Link sent by the company', 'Friend or referral', 'Social media', 'Other']],
        ]],
        'about' => ['title' => '2. About you', 'fields' => [
            'name' => ['label' => 'Full name (as on your ID)', 'type' => 'text', 'req' => 1, 'core' => 1, 'max' => 100, 'ac' => 'name'],
            'email' => ['label' => 'Email address', 'type' => 'email', 'req' => 1, 'core' => 1, 'max' => 150, 'ac' => 'email'],
            'phone' => ['label' => 'Phone number (with country code)', 'type' => 'tel', 'req' => 1, 'core' => 1, 'max' => 25, 'ac' => 'tel', 'hint' => 'For example: +254 712 345 678'],
            'whatsapp' => ['label' => 'WhatsApp number (if different)', 'type' => 'tel', 'req' => 0, 'max' => 25],
            'country' => ['label' => 'Country you live in', 'type' => 'country', 'req' => 1, 'core' => 1],
            'city' => ['label' => 'City or town', 'type' => 'text', 'req' => 1, 'core' => 1, 'max' => 80],
            'dob' => ['label' => 'Date of birth', 'type' => 'date', 'req' => 1, 'core' => 1, 'hint' => 'You must be 18 or older.'],
            'gender' => ['label' => 'Gender (optional)', 'type' => 'select', 'req' => 0, 'core' => 1, 'opts' => ['Female', 'Male', 'Prefer not to say']],
            'languages' => ['label' => 'Languages you speak and how well', 'type' => 'text', 'req' => 1, 'max' => 150, 'hint' => 'For example: English (fluent), Swahili (native), French (basic).'],
            'right_to_work' => ['label' => 'Do you have the legal right to work in the country where you will work?', 'type' => 'select', 'req' => 1, 'opts' => ['Yes', 'No, I would need a permit']],
        ]],
        'experience' => ['title' => '3. Education and experience', 'fields' => [
            'education' => ['label' => 'Highest education', 'type' => 'select', 'req' => 1, 'opts' => ['Secondary school', 'Certificate / Diploma', "Bachelor's degree", "Master's degree", 'PhD', 'Other']],
            'study' => ['label' => 'Course or field of study', 'type' => 'text', 'req' => 0, 'max' => 120],
            'institution' => ['label' => 'School, college or university', 'type' => 'text', 'req' => 0, 'max' => 120],
            'certs' => ['label' => 'Certificates and training (marketing, sales, digital skills)', 'type' => 'textarea', 'req' => 0, 'max' => 800, 'rows' => 3],
            'exp_total' => ['label' => 'Total years of work experience', 'type' => 'select', 'req' => 1, 'opts' => ['None yet', 'Under 1 year', '1 to 2 years', '3 to 5 years', '6 to 10 years', 'More than 10 years']],
            'exp_field' => ['label' => 'Years of experience in marketing or sales', 'type' => 'select', 'req' => 1, 'opts' => ['None yet', 'Under 1 year', '1 to 2 years', '3 to 5 years', '6 to 10 years', 'More than 10 years']],
            'employer' => ['label' => 'Current or last employer', 'type' => 'text', 'req' => 0, 'max' => 120],
            'job_title' => ['label' => 'Your job title there', 'type' => 'text', 'req' => 0, 'max' => 100],
            'history' => ['label' => 'Your last two jobs: company, title, dates and what you did', 'type' => 'textarea', 'req' => 1, 'min' => 50, 'max' => 2000, 'rows' => 6],
            'industry' => ['label' => 'Have you worked in betting, gaming or sports media?', 'type' => 'select', 'req' => 1, 'opts' => ['Yes, in betting or gaming', 'Yes, in sports media', 'In another online business', 'In retail or sales', 'No, not yet']],
            'skills' => ['label' => 'Your skills (tick all that apply)', 'type' => 'checks', 'req' => 1, 'opts' => ['Social media marketing', 'Facebook / Instagram ads', 'Google ads', 'TikTok ads', 'SEO', 'Email marketing', 'Affiliate marketing', 'Influencer outreach',
                'Content writing', 'Video editing', 'Graphic design', 'Telegram / WhatsApp communities', 'Cold calling', 'Sales negotiation', 'Customer support', 'Data and analytics', 'CRM tools']],
            'tools' => ['label' => 'Tools you use well', 'type' => 'text', 'req' => 0, 'max' => 200, 'hint' => 'For example: Meta Ads Manager, Canva, Google Analytics, HubSpot.'],
        ]],
        'questions' => ['title' => '4. Questions about the job', 'fields' => [
            'achievement' => ['label' => 'Your biggest marketing or sales achievement (use numbers)', 'type' => 'textarea', 'req' => 1, 'min' => 50, 'max' => 1500, 'rows' => 5],
            'plan' => ['label' => 'How would you win our first 1,000 paying members in your country in 90 days?', 'type' => 'textarea', 'req' => 1, 'min' => 80, 'max' => 2000, 'rows' => 7,
                       'hint' => 'Tell us the channels, the first steps, the weekly numbers you would aim for and how you would measure them.'],
            'sales_story' => ['label' => 'Tell us about a time you closed a hard sale or won back a customer', 'type' => 'textarea', 'req' => 0, 'max' => 1500, 'rows' => 4],
            'audience' => ['label' => 'Your own audience, if any (platform and followers)', 'type' => 'text', 'req' => 0, 'max' => 200, 'hint' => 'For example: Facebook page 12,000 followers; Telegram group 3,500 members.'],
            'markets' => ['label' => 'Which countries or markets can you sell in?', 'type' => 'text', 'req' => 1, 'max' => 200],
            'hours' => ['label' => 'Hours you can work each week', 'type' => 'select', 'req' => 1, 'opts' => ['Under 20', '20 to 30', '30 to 40', 'More than 40']],
            'equipment' => ['label' => 'Do you have a working laptop or smartphone and reliable internet?', 'type' => 'radio', 'req' => 1, 'opts' => $yn],
            'rg' => ['label' => 'Betting is for adults (18+) and the law differs by country. We only promote responsible gambling and never promise wins. Do you agree to follow this?', 'type' => 'radio', 'req' => 1, 'opts' => ['Yes, I agree', 'No']],
            'why' => ['label' => 'Why do you want to work with us?', 'type' => 'textarea', 'req' => 1, 'min' => 40, 'max' => 1500, 'rows' => 4],
            'anything' => ['label' => 'Anything else we should know?', 'type' => 'textarea', 'req' => 0, 'max' => 1000, 'rows' => 3],
        ]],
        'online' => ['title' => '5. Your online profiles (optional, but they help)', 'fields' => [
            'linkedin' => ['label' => 'LinkedIn', 'type' => 'url', 'req' => 0, 'max' => 200],
            'website' => ['label' => 'Website, portfolio or blog', 'type' => 'url', 'req' => 0, 'max' => 200],
            'facebook' => ['label' => 'Facebook', 'type' => 'url', 'req' => 0, 'max' => 200],
            'instagram' => ['label' => 'Instagram', 'type' => 'url', 'req' => 0, 'max' => 200],
            'tiktok' => ['label' => 'TikTok', 'type' => 'url', 'req' => 0, 'max' => 200],
            'xtwitter' => ['label' => 'X (Twitter)', 'type' => 'url', 'req' => 0, 'max' => 200],
            'youtube' => ['label' => 'YouTube', 'type' => 'url', 'req' => 0, 'max' => 200],
            'telegram' => ['label' => 'Telegram', 'type' => 'text', 'req' => 0, 'max' => 120],
        ]],
        'refs' => ['title' => '6. References (people who know your work)', 'fields' => [
            'ref1_name' => ['label' => 'Referee 1: full name', 'type' => 'text', 'req' => 1, 'max' => 100],
            'ref1_rel' => ['label' => 'Referee 1: who are they to you?', 'type' => 'text', 'req' => 1, 'max' => 100, 'hint' => 'For example: former manager at Acme Ltd.'],
            'ref1_contact' => ['label' => 'Referee 1: phone or email', 'type' => 'text', 'req' => 1, 'max' => 150],
            'ref2_name' => ['label' => 'Referee 2: full name', 'type' => 'text', 'req' => 0, 'max' => 100],
            'ref2_rel' => ['label' => 'Referee 2: who are they to you?', 'type' => 'text', 'req' => 0, 'max' => 100],
            'ref2_contact' => ['label' => 'Referee 2: phone or email', 'type' => 'text', 'req' => 0, 'max' => 150],
        ]],
    ];
}

/** The documents to upload. kinds: pdf, doc, img. */
function job_files(): array
{
    return [
        'photo' => ['label' => 'Passport-style photo of yourself', 'req' => 1, 'kinds' => ['img'], 'hint' => 'A clear, recent photo of your face. JPG, PNG or WebP.'],
        'idscan' => ['label' => 'National ID or passport (photo or scan of the page with your photo)', 'req' => 1, 'kinds' => ['img', 'pdf'], 'hint' => 'JPG, PNG, WebP or PDF. Used only to check who you are. Never send bank or card details.'],
        'cv' => ['label' => 'Your CV', 'req' => 1, 'kinds' => ['pdf', 'doc'], 'hint' => 'PDF, DOC or DOCX.'],
        'certs' => ['label' => 'Certificates (optional)', 'req' => 0, 'kinds' => ['pdf', 'img'], 'hint' => 'One PDF or picture with your certificates.'],
        'work' => ['label' => 'Proof of your work (optional)', 'req' => 0, 'kinds' => ['pdf', 'img'], 'hint' => 'A screenshot of results, a campaign report or a portfolio PDF.'],
    ];
}

function job_dir(): string
{
    $dir = dirname(__DIR__) . '/private-uploads/jobs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** What is this file really? Returns pdf | doc | docx | jpg | png | webp | ''. */
function job_file_kind(string $tmp, string $origName): string
{
    $head = (string)@file_get_contents($tmp, false, null, 0, 16);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (strncmp($head, '%PDF', 4) === 0) { return 'pdf'; }
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) { return 'jpg'; }
    if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) { return 'png'; }
    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') { return 'webp'; }
    if (strncmp($head, "PK\x03\x04", 4) === 0 && $ext === 'docx') { return 'docx'; }
    if (strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0 && $ext === 'doc') { return 'doc'; }
    return '';
}

/**
 * Stores one uploaded document in the private folder. Returns the stored file name, or null with $err set ($err empty = nothing was uploaded).
 */
function job_save_file(?array $file, array $allowed, string &$err): ?string
{
    $err = '';
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'is too big (5 MB maximum)' : 'could not be uploaded, please try again';
        return null;
    }
    if ($file['size'] > 5 * 1024 * 1024) { $err = 'is too big (5 MB maximum)'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $err = 'could not be uploaded, please try again'; return null; }
    $kind = job_file_kind($file['tmp_name'], (string)$file['name']);
    $map = ['pdf' => 'pdf', 'doc' => 'doc', 'docx' => 'doc', 'jpg' => 'img', 'png' => 'img', 'webp' => 'img'];
    if ($kind === '' || !in_array($map[$kind], $allowed, true)) {
        $names = ['pdf' => 'PDF', 'doc' => 'DOC or DOCX', 'img' => 'JPG, PNG or WebP picture'];
        $err = 'must be a ' . implode(' or ', array_map(function ($k) use ($names) { return $names[$k]; }, $allowed));
        return null;
    }
    if ($map[$kind] === 'img') {
        $info = @getimagesize($file['tmp_name']);
        if (!$info || $info[0] < 200 || $info[1] < 200 || $info[0] > 10000 || $info[1] > 10000) { $err = 'must be a clear picture, at least 200 x 200 pixels'; return null; }
    }
    $name = bin2hex(random_bytes(12)) . '.' . $kind;
    if (!@move_uploaded_file($file['tmp_name'], job_dir() . '/' . $name)) { $err = 'could not be saved (the folder private-uploads/jobs must be writable)'; return null; }
    return $name;
}

function job_file_path(string $name): ?string
{
    if (!preg_match('/^[a-f0-9]{24}\.(pdf|doc|docx|jpg|png|webp)$/', $name)) { return null; }
    $p = job_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

function job_delete_files(array $row): void
{
    foreach (['photo_file', 'id_file', 'cv_file', 'cert_file', 'work_file'] as $c) {
        if (!empty($row[$c]) && ($p = job_file_path((string)$row[$c]))) { @unlink($p); }
    }
}

function job_new_ref(): string { return 'JOB-' . strtoupper(bin2hex(random_bytes(3))); }

function jobs_new_count(): int
{
    try { return (int)db()->query("SELECT COUNT(*) FROM job_applications WHERE status = 'new'")->fetchColumn(); } catch (Throwable $e) { return 0; }
}
