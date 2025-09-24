<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$exportBaseDir = $rootDir . '/tmp/gui-exports';
if (!is_dir($exportBaseDir)) {
    @mkdir($exportBaseDir, 0777, true);
}

$errorMessages = [];
$successMessages = [];
$runResults = [];
$exportDir = null;
$combinedFile = null;
$mode = $_POST['mode'] ?? 'site';
$siteUrl = trim((string)($_POST['site_url'] ?? ''));
$customUrlsInput = (string)($_POST['custom_urls'] ?? '');
$customUrls = [];
$maxDepth = isset($_POST['max_depth']) ? (int)$_POST['max_depth'] : 0;
$singlePage = isset($_POST['single_page']);
$combineMarkdown = isset($_POST['combine_markdown']);
$moveBeforeH1 = isset($_POST['move_before_h1']);
$disableImages = isset($_POST['disable_images']);
$disableFiles = isset($_POST['disable_files']);
$excludeSelectorsRaw = (string)($_POST['exclude_selectors'] ?? '');
$excludeSelectors = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $excludeSelectorsRaw))));
$extraOptionsRaw = trim((string)($_POST['extra_options'] ?? ''));
$extraOptions = parseExtraOptions($extraOptionsRaw);
$downloadRequest = $_GET['download'] ?? null;

if ($downloadRequest !== null) {
    serveDownload($downloadRequest, $exportBaseDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'site') {
        if ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            $errorMessages[] = 'Please provide a valid site URL.';
        }
    } else {
        $customUrls = extractUrls($customUrlsInput);
        if (!$customUrls) {
            $errorMessages[] = 'Add at least one valid URL in the list.';
        }
    }

    if (!$errorMessages) {
        $exportDir = buildExportDir($exportBaseDir, $mode === 'site' ? $siteUrl : ($customUrls[0] ?? 'custom'));
        $combinedFile = $combineMarkdown ? $exportDir . '/combined.md' : null;
        $commonArguments = buildCommonArguments($exportDir, $combinedFile, $moveBeforeH1, $disableImages, $disableFiles, $excludeSelectors);
        $commonArguments[] = '--hide-progress-bar';
        if (!hasOptionOverride($extraOptions, ['--no-color', '--force-color'])) {
            $commonArguments[] = '--no-color';
        }
        $commonArguments = array_merge($commonArguments, $extraOptions);

        if ($mode === 'site') {
            $arguments = array_merge($commonArguments, buildSiteArguments($siteUrl, $singlePage, $maxDepth));
            $runResults[] = runCrawler($arguments, $rootDir);
        } else {
            foreach ($customUrls as $url) {
                $arguments = array_merge($commonArguments, buildCustomUrlArguments($url));
                $runResults[] = runCrawler($arguments, $rootDir);
            }
        }

        $allSuccessful = !array_filter($runResults, fn(array $result) => $result['exitCode'] !== 0);
        if ($allSuccessful) {
            $successMessages[] = 'Markdown export finished.';
        } else {
            $errorMessages[] = 'At least one run finished with an error. Review the details below.';
        }
        if ($exportDir && is_dir($exportDir)) {
            $successMessages[] = 'Exported markdown files: <code>' . htmlspecialchars($exportDir, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }
        if ($combinedFile && is_file($combinedFile)) {
            $successMessages[] = 'Combined markdown file: <code>' . htmlspecialchars($combinedFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }
    }
}

function serveDownload(string $encodedPath, string $exportBaseDir): void
{
    $normalized = strtr($encodedPath, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $relativePath = base64_decode($normalized, true);
    if ($relativePath === false) {
        http_response_code(400);
        exit('Invalid download request.');
    }

    $relativePath = ltrim($relativePath, '/');
    $fullPath = realpath($exportBaseDir . '/' . $relativePath);
    $exportBaseReal = realpath($exportBaseDir);

    if ($fullPath === false || $exportBaseReal === false || !str_starts_with($fullPath, $exportBaseReal)) {
        http_response_code(404);
        exit('File not found.');
    }

    if (!is_file($fullPath)) {
        http_response_code(404);
        exit('File not found.');
    }

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
    readfile($fullPath);
    exit;
}

function extractUrls(string $input): array
{
    $urls = [];
    foreach (preg_split('/\r?\n/', $input) as $line) {
        $url = trim($line);
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            $urls[] = $url;
        }
    }
    return array_values(array_unique($urls));
}

function buildExportDir(string $exportBaseDir, string $referenceUrl): string
{
    $parts = parse_url($referenceUrl);
    $host = $parts['host'] ?? 'export';
    $slug = slugify($host);
    $timestamp = date('Ymd-His');
    $target = $exportBaseDir . '/' . $timestamp . '-' . $slug;
    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }
    return $target;
}

function slugify(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?: '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'export';
}

function buildCommonArguments(string $exportDir, ?string $combinedFile, bool $moveBeforeH1, bool $disableImages, bool $disableFiles, array $excludeSelectors): array
{
    $arguments = [
        '--markdown-export-dir=' . $exportDir,
    ];

    if ($combinedFile) {
        $arguments[] = '--markdown-export-single-file=' . $combinedFile;
    }
    if ($moveBeforeH1) {
        $arguments[] = '--markdown-move-content-before-h1-to-end';
    }
    if ($disableImages) {
        $arguments[] = '--markdown-disable-images';
    }
    if ($disableFiles) {
        $arguments[] = '--markdown-disable-files';
    }
    foreach ($excludeSelectors as $selector) {
        $arguments[] = '--markdown-exclude-selector=' . $selector;
    }

    return $arguments;
}

function parseExtraOptions(string $extraOptionsRaw): array
{
    if ($extraOptionsRaw === '') {
        return [];
    }

    $options = [];
    foreach (preg_split('/\r?\n/', $extraOptionsRaw) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $options[] = $line;
        }
    }

    return $options;
}

function hasOptionOverride(array $options, array $keywords): bool
{
    foreach ($options as $option) {
        foreach ($keywords as $keyword) {
            if (str_contains($option, $keyword)) {
                return true;
            }
        }
    }
    return false;
}

function buildSiteArguments(string $url, bool $singlePage, int $maxDepth): array
{
    $arguments = ['--url=' . $url];
    if ($singlePage) {
        $arguments[] = '--single-page';
    }
    if ($maxDepth > 0) {
        $arguments[] = '--max-depth=' . $maxDepth;
    }
    return $arguments;
}

function buildCustomUrlArguments(string $url): array
{
    return [
        '--url=' . $url,
        '--single-page',
    ];
}

function runCrawler(array $arguments, string $workingDir): array
{
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWindows) {
        $executable = $workingDir . '\\crawler.bat';
        $command = array_merge(['cmd.exe', '/C', $executable], $arguments);
    } else {
        $executable = $workingDir . '/crawler';
        $command = array_merge([$executable], $arguments);
    }

    if (!file_exists($executable)) {
        return [
            'command' => implode(' ', array_map('escapeshellarg', $command)),
            'exitCode' => -1,
            'stdout' => '',
            'stderr' => 'Crawler executable not found at ' . $executable,
        ];
    }
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $workingDir);
    if (!is_resource($process)) {
        return [
            'command' => implode(' ', array_map('escapeshellarg', $command)),
            'exitCode' => -1,
            'stdout' => '',
            'stderr' => 'Unable to start crawler process.',
        ];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'command' => implode(' ', array_map('escapeshellarg', $command)),
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function listExportedMarkdown(string $exportDir, string $exportBaseDir): array
{
    if ($exportDir === null || !is_dir($exportDir)) {
        return [];
    }
    $files = [];
    $directoryIterator = new \RecursiveDirectoryIterator($exportDir, \FilesystemIterator::SKIP_DOTS);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'md') {
            $files[] = $fileInfo->getPathname();
        }
    }
    sort($files);
    $list = [];
    foreach ($files as $file) {
        $relative = substr($file, strlen($exportBaseDir) + 1);
        $list[] = [
            'name' => basename($file),
            'relative' => $relative,
            'download_token' => encodeDownloadToken($relative),
        ];
    }
    return $list;
}

function encodeDownloadToken(string $relativePath): string
{
    return rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=');
}

$exportedFiles = listExportedMarkdown($exportDir, $exportBaseDir);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SiteOne Crawler - Simple Markdown GUI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        body {
            margin: 0 auto;
            padding: 2rem 1.5rem 4rem;
            max-width: 960px;
            line-height: 1.6;
            background: var(--body-bg, #f5f5f7);
            color: var(--body-fg, #1f2933);
        }
        h1 {
            margin-top: 0;
            text-align: center;
        }
        form {
            background: rgba(255,255,255,0.9);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.1);
        }
        fieldset {
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 10px;
            margin-bottom: 1.5rem;
            padding: 1rem 1.25rem;
        }
        legend {
            font-weight: 600;
            color: #4338ca;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }
        input[type="text"], input[type="number"], textarea {
            width: 100%;
            padding: 0.65rem 0.75rem;
            border-radius: 8px;
            border: 1px solid rgba(99, 102, 241, 0.3);
            background: rgba(250, 250, 255, 0.95);
            font-size: 1rem;
        }
        textarea {
            min-height: 140px;
        }
        .option-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        button {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            border: none;
            padding: 0.85rem 1.4rem;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(79, 70, 229, 0.25);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 28px rgba(79, 70, 229, 0.25);
        }
        .messages {
            margin: 1.5rem 0;
        }
        .messages > div {
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 0.75rem;
        }
        .messages .error {
            background: rgba(248, 113, 113, 0.15);
            border: 1px solid rgba(248, 113, 113, 0.35);
            color: #991b1b;
        }
        .messages .success {
            background: rgba(134, 239, 172, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.4);
            color: #166534;
        }
        pre {
            background: #0f172a;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 10px;
            overflow-x: auto;
            font-size: 0.9rem;
        }
        .runs {
            margin-top: 2rem;
        }
        .run {
            margin-bottom: 2rem;
            border: 1px solid rgba(148, 163, 184, 0.4);
            border-radius: 12px;
            padding: 1.25rem;
            background: rgba(248, 250, 252, 0.85);
        }
        .files {
            margin-top: 1.5rem;
            border: 1px solid rgba(148, 163, 184, 0.4);
            border-radius: 12px;
            padding: 1.25rem;
            background: rgba(248, 250, 252, 0.85);
        }
        .files ul {
            list-style: none;
            padding-left: 0;
        }
        .files li {
            padding: 0.35rem 0;
        }
        .files a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
        .note {
            font-size: 0.9rem;
            color: #475569;
        }
        @media (max-width: 640px) {
            body {
                padding: 1.25rem 1rem 3rem;
            }
            fieldset {
                padding: 1rem;
            }
            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<h1>SiteOne Crawler – Markdown GUI</h1>
<p class="note">Run the crawler with friendly controls. Choose to crawl an entire site or a custom list of pages and export everything into Markdown.</p>
<div class="messages">
    <?php foreach ($errorMessages as $message): ?>
        <div class="error">⚠️ <?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endforeach; ?>
    <?php foreach ($successMessages as $message): ?>
        <div class="success">✅ <?= $message ?></div>
    <?php endforeach; ?>
</div>
<form method="post">
    <fieldset>
        <legend>What would you like to crawl?</legend>
        <div class="option-row">
            <input type="radio" id="mode-site" name="mode" value="site" <?= $mode === 'site' ? 'checked' : '' ?>>
            <label for="mode-site">Entire site starting from URL</label>
        </div>
        <div style="margin-left: 2.2rem; margin-bottom: 1rem;">
            <label for="site_url">Starting URL</label>
            <input type="text" id="site_url" name="site_url" placeholder="https://example.com" value="<?= htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div style="display:flex; gap: 1rem; margin-top: 0.75rem;">
                <label style="flex:1;">
                    <span>Maximum depth (0 = unlimited)</span>
                    <input type="number" min="0" id="max_depth" name="max_depth" value="<?= htmlspecialchars((string)$maxDepth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </label>
                <label style="flex:1; display:flex; align-items:center; gap:0.5rem; margin-top:1.6rem;">
                    <input type="checkbox" name="single_page" <?= $singlePage ? 'checked' : '' ?>>
                    <span>Only export this page</span>
                </label>
            </div>
        </div>
        <div class="option-row">
            <input type="radio" id="mode-list" name="mode" value="list" <?= $mode === 'list' ? 'checked' : '' ?>>
            <label for="mode-list">Custom list of URLs (one per line)</label>
        </div>
        <div style="margin-left: 2.2rem;">
            <textarea name="custom_urls" placeholder="https://example.com/docs/page-1
https://example.com/docs/page-2
https://example.com/blog/post" <?= $mode === 'list' ? '' : 'disabled' ?>><?= htmlspecialchars($customUrlsInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>
    </fieldset>

    <fieldset>
        <legend>Markdown export settings</legend>
        <div class="option-row">
            <input type="checkbox" id="combine_markdown" name="combine_markdown" <?= $combineMarkdown ? 'checked' : '' ?>>
            <label for="combine_markdown">Create single combined markdown file</label>
        </div>
        <div class="option-row">
            <input type="checkbox" id="move_before_h1" name="move_before_h1" <?= $moveBeforeH1 ? 'checked' : '' ?>>
            <label for="move_before_h1">Move menus/headers that appear before the first H1 to the end</label>
        </div>
        <div class="option-row">
            <input type="checkbox" id="disable_images" name="disable_images" <?= $disableImages ? 'checked' : '' ?>>
            <label for="disable_images">Skip images in markdown output</label>
        </div>
        <div class="option-row">
            <input type="checkbox" id="disable_files" name="disable_files" <?= $disableFiles ? 'checked' : '' ?>>
            <label for="disable_files">Skip non-image file downloads (PDF, ZIP, …)</label>
        </div>
        <label for="exclude_selectors">Exclude elements (CSS selectors, one per line)</label>
        <textarea id="exclude_selectors" name="exclude_selectors" placeholder="header
.footer
nav.sidebar"><?= htmlspecialchars($excludeSelectorsRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    </fieldset>

    <fieldset>
        <legend>Advanced options</legend>
        <label for="extra_options">Additional crawler options (one per line, e.g. <code>--max-depth=2</code>)</label>
        <textarea id="extra_options" name="extra_options" placeholder="--remove-query-params
--markdown-replace-content=/foo/ -> bar"><?= htmlspecialchars($extraOptionsRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        <p class="note">These options will be appended to each crawler run. Use the same syntax as on the command line.</p>
    </fieldset>

    <button type="submit">Start markdown export</button>
</form>

<?php if ($exportedFiles): ?>
    <div class="files">
        <h2>Exported markdown files</h2>
        <ul>
            <?php foreach ($exportedFiles as $file): ?>
                <li><a href="?download=<?= htmlspecialchars($file['download_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">⬇ <?= htmlspecialchars($file['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
        </ul>
        <p class="note">Files are stored in <code><?= htmlspecialchars($exportDir ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>.</p>
    </div>
<?php endif; ?>

<?php if ($runResults): ?>
    <div class="runs">
        <h2>Run details</h2>
        <?php foreach ($runResults as $index => $result): ?>
            <div class="run">
                <h3>Run <?= $index + 1 ?> — exit code <?= (int)$result['exitCode'] ?></h3>
                <p class="note">Command: <code><?= htmlspecialchars($result['command'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
                <?php if ($result['stdout'] !== ''): ?>
                    <h4>Output</h4>
                    <pre><?= htmlspecialchars($result['stdout'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                <?php endif; ?>
                <?php if ($result['stderr'] !== ''): ?>
                    <h4>Errors</h4>
                    <pre><?= htmlspecialchars($result['stderr'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
    const modeSiteRadio = document.getElementById('mode-site');
    const modeListRadio = document.getElementById('mode-list');
    const customUrlsTextarea = document.querySelector('textarea[name="custom_urls"]');

    function toggleListInput() {
        if (!customUrlsTextarea) return;
        if (modeListRadio.checked) {
            customUrlsTextarea.removeAttribute('disabled');
        } else {
            customUrlsTextarea.setAttribute('disabled', 'disabled');
        }
    }

    modeSiteRadio.addEventListener('change', toggleListInput);
    modeListRadio.addEventListener('change', toggleListInput);
    toggleListInput();
</script>
</body>
</html>
