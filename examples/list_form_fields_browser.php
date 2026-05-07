<?php

declare(strict_types=1);

use PdfToolkit\Graphics\Color;
use PdfToolkit\Import\ImportedFormField;
use PdfToolkit\Pdf;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Edit These Values
|--------------------------------------------------------------------------
|
| Load this file in your browser and it will show a numbered field map:
| - the PDF gets small numbered markers near each field
| - the field names are shown in a selectable sidebar
|
| Add ?pdf=1 for the annotated PDF only.
| Add ?plain=1 for a plain-text field list.
|
*/

$sourcePath = __DIR__ . '/f1099msc.pdf';
$downloadName = 'annotated-form-fields.pdf';
$safeDownloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'document.pdf';
$markerFontSize = 7.0;
$markerSize = 12.0;
$highlightColor = Color::rgb(0.86, 0.12, 0.12);
$showTooltip = true;

if (!is_file($sourcePath)) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "Source PDF not found.\n";
    echo "Update \$sourcePath in " . basename(__FILE__) . " to point at a real PDF file.\n";
    echo "Current value: {$sourcePath}\n";
    exit;
}

try {
    $imported = Pdf::load($sourcePath);
    $fields = $imported->form()->fields();
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "Unable to inspect PDF form fields.\n\n";
    echo $e::class . ': ' . $e->getMessage() . "\n";
    exit;
}

if (isset($_GET['plain']) && $_GET['plain'] !== '0') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Source: {$sourcePath}\n";
    echo 'Field count: ' . count($fields) . "\n\n";

    if ($fields === []) {
        echo "No AcroForm field names were found.\n";
        exit;
    }

    foreach ($fields as $index => $field) {
        echo sprintf(
            "%d. Page %d | %s | %s\n",
            $index + 1,
            $field->pageNumber,
            $field->type,
            $field->name
        );
    }

    exit;
}

if (isset($_GET['pdf']) && $_GET['pdf'] !== '0') {
    foreach ($fields as $index => $field) {
        annotateField(
            $imported,
            $field,
            $index + 1,
            $markerSize,
            $markerFontSize,
            $highlightColor,
        );
    }

    $bytes = $imported->save();

    header('Content-Type: application/pdf');
    header(sprintf('Content-Disposition: inline; filename="%s"', $safeDownloadName));
    header('Content-Length: ' . strlen($bytes));

    echo $bytes;
    exit;
}

$self = basename(__FILE__);
$pdfUrl = htmlspecialchars($self . '?pdf=1', ENT_QUOTES, 'UTF-8');
$plainUrl = htmlspecialchars($self . '?plain=1', ENT_QUOTES, 'UTF-8');
$sourceLabel = htmlspecialchars($sourcePath, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDF Form Field Map</title>
    <style>
        :root {
            --bg: #f4f1e8;
            --panel: #fffdf8;
            --ink: #1f1a14;
            --muted: #6c6257;
            --accent: #b22020;
            --line: #d8cdbf;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, #fffaf0 0, transparent 28rem),
                linear-gradient(180deg, #f8f4ec 0%, var(--bg) 100%);
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(20rem, 28rem) 1fr;
            min-height: 100vh;
        }

        .sidebar {
            border-right: 1px solid var(--line);
            background: rgba(255, 253, 248, 0.96);
            padding: 1.25rem;
            overflow: auto;
        }

        .viewer {
            min-height: 100vh;
            background: #d9d2c7;
        }

        .viewer iframe {
            width: 100%;
            height: 100vh;
            border: 0;
            background: white;
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
            line-height: 1.1;
        }

        .lede,
        .source {
            margin: 0 0 1rem;
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.45;
        }

        .links {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .links a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 700;
        }

        .count {
            margin: 0 0 1rem;
            font-weight: 700;
        }

        .field-list {
            display: grid;
            gap: 0.75rem;
        }

        .field {
            padding: 0.8rem 0.9rem;
            background: white;
            border: 1px solid var(--line);
            border-radius: 0.7rem;
            box-shadow: 0 0.35rem 1rem rgba(31, 26, 20, 0.04);
        }

        .field-head {
            display: flex;
            align-items: baseline;
            gap: 0.6rem;
            margin-bottom: 0.35rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.7rem;
            height: 1.7rem;
            padding: 0 0.35rem;
            border-radius: 999px;
            background: var(--accent);
            color: white;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .name {
            font-size: 1rem;
            line-height: 1.35;
            word-break: break-word;
        }

        .meta {
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.4;
        }

        .tooltip {
            margin-top: 0.35rem;
            color: var(--ink);
            font-size: 0.92rem;
            line-height: 1.4;
        }

        code {
            font-family: "SFMono-Regular", Menlo, Consolas, monospace;
            font-size: 0.92em;
        }

        @media (max-width: 980px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .sidebar {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .viewer,
            .viewer iframe {
                height: 70vh;
                min-height: 32rem;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <h1>PDF Form Field Map</h1>
            <p class="lede">The PDF on the right uses small numbered markers so it stays readable. The full field names are listed here for easy copy and selection.</p>
            <p class="source"><strong>Source:</strong> <code><?= $sourceLabel ?></code></p>
            <div class="links">
                <a href="<?= $pdfUrl ?>" target="_blank" rel="noopener noreferrer">Open Annotated PDF</a>
                <a href="<?= $plainUrl ?>" target="_blank" rel="noopener noreferrer">Plain Text List</a>
            </div>
            <p class="count">Field count: <?= count($fields) ?></p>

            <div class="field-list">
                <?php foreach ($fields as $index => $field): ?>
                    <section class="field">
                        <div class="field-head">
                            <span class="badge"><?= $index + 1 ?></span>
                            <div class="name"><code><?= h($field->name) ?></code></div>
                        </div>
                        <div class="meta">Page <?= $field->pageNumber ?> | Type <?= h($field->type) ?> | Rect <?= h(formatRect($field->rect)) ?></div>
                        <?php if ($showTooltip && $field->tooltip !== null && $field->tooltip !== ''): ?>
                            <div class="tooltip"><?= h($field->tooltip) ?></div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="viewer">
            <iframe src="<?= $pdfUrl ?>" title="Annotated PDF field map"></iframe>
        </main>
    </div>
</body>
</html>
<?php

function annotateField(
    \PdfToolkit\Import\ImportedDocument $imported,
    ImportedFormField $field,
    int $index,
    float $markerSize,
    float $markerFontSize,
    Color $highlightColor,
): void {
    $page = $imported->document()->page($field->pageNumber - 1);
    $pageHeight = $page->height();

    $fieldX = $field->x();
    $fieldTopY = $pageHeight - $field->top();
    $fieldWidth = $field->width();
    $fieldHeight = $field->height();

    $markerX = max(2.0, $fieldX - $markerSize - 2.0);
    $markerY = max(2.0, $fieldTopY - $markerSize - 2.0);

    if ($fieldTopY < $markerSize + 4.0) {
        $markerY = $fieldTopY + 2.0;
    }

    $imported
        ->pages()
        ->page($field->pageNumber)
        ->overlayRectangle(
            $fieldX,
            $fieldTopY,
            $fieldWidth,
            $fieldHeight,
            strokeColor: $highlightColor,
            lineWidth: 0.75,
        )
        ->overlayRectangle(
            $markerX,
            $markerY,
            $markerSize,
            $markerSize,
            strokeColor: $highlightColor,
            fillColor: Color::white(),
            lineWidth: 0.75,
        )
        ->overlayText((string) $index, x: $markerX + 2.0, y: $markerY + 1.0, fontSize: $markerFontSize)
        ->done()
        ->done();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param list<float> $rect
 */
function formatRect(array $rect): string
{
    return implode(', ', array_map(
        static fn (float $value): string => rtrim(rtrim(sprintf('%.2F', $value), '0'), '.'),
        $rect
    ));
}
