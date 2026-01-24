<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <% base_tag %>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$Title</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        /* Transparency checkerboard for image backgrounds */
        .image-box {
            display: inline-block;
            background: repeating-conic-gradient(#e8e8e8 0% 25%, #fff 0% 50%) 50% / 16px 16px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px;
        }
        .image-box img {
            display: block;
            max-width: 250px;
            max-height: 200px;
        }
        .result-cell .image-box img {
            max-width: 200px;
            max-height: 150px;
        }
        /* Setup divider styling */
        .setup-divider::before,
        .setup-divider::after {
            content: '';
            display: inline-block;
            width: 80px;
            height: 1px;
            background: #dee2e6;
            vertical-align: middle;
            margin: 0 15px;
        }
        /* Chained row indicator */
        tr.chained td:first-child {
            border-left: 3px solid #6f42c1;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4" style="max-width: 1800px;">

<% if $ShowSetup %>
    <%-- SETUP PAGE --%>
    <div class="card mx-auto text-center" style="max-width: 600px; margin-top: 40px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-4">SVG vs PNG Manipulation Comparison</h1>

            <% if $Error %>
                <div class="alert alert-danger">$Error</div>
            <% end_if %>

            <p class="text-muted">This tool compares SVG image manipulations with PNG equivalents to verify consistent behavior.</p>

            <div class="mt-4">
                <p><strong>Option 1:</strong> Install bundled test images</p>
                <p class="small text-muted mb-3">
                    Creates 4 test files in <code>assets/svg-compare-test/</code><br>
                    (2 published + 2 draft for testing protected assets)
                </p>
                <a href="$InstallURL" class="btn btn-success" onclick="return confirm('Install test images to the database?');">
                    Install Test Images &amp; Run Comparison
                </a>
            </div>

            <div class="my-4 text-muted setup-divider">or</div>

            <div>
                <p><strong>Option 2:</strong> Use your own images</p>
                <form class="d-flex gap-2 justify-content-center flex-wrap" method="get">
                    <input type="number" name="svg" class="form-control" placeholder="SVG ID" required style="width: 120px;">
                    <input type="number" name="png" class="form-control" placeholder="PNG ID" required style="width: 120px;">
                    <button type="submit" class="btn btn-primary">Run Comparison</button>
                </form>
            </div>
        </div>
    </div>

<% else %>

    <%-- COMPARISON PAGE --%>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <h1 class="h4 mb-0">$Title</h1>
        <% if $UsingTestImages %>
            <a href="$RemoveURL" class="btn btn-danger btn-sm" onclick="return confirm('Remove all test images from the database?');">
                Remove Test Images
            </a>
        <% else %>
            <a href="{$Top.Link}" class="btn btn-dark btn-sm">← Back to setup</a>
        <% end_if %>
    </div>

<div class="row">

    <div class="col-6">
        <div class="card mb-4">
            <div class="card-body py-2">
                <small class="text-muted">
                    <strong>Test Images:</strong>
                    SVG: <code>$SVGImage.Name</code> (ID: $SVGImage.ID, $OriginalSVG.Dimensions)
                    &nbsp;|&nbsp;
                    PNG: <code>$PNGImage.Name</code> (ID: $PNGImage.ID, $OriginalPNG.Dimensions)
                    <% if not $UsingTestImages %>
                        &nbsp;|&nbsp; <a href="{$Top.Link}">← Back to setup</a>
                    <% end_if %>
                </small>
            </div>
        </div>
        <%-- Original images --%>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">Original SVG <span class="badge bg-success">SVG</span></h3>
                        <div class="image-box">
                            <img src="$OriginalSVG.URL" alt="Original SVG">
                        </div>
                        <div class="mt-2 small text-muted">
                            <code class="d-block text-break">$OriginalSVG.Filename</code>
                            $OriginalSVG.Dimensions
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">Original PNG <span class="badge bg-primary">PNG</span></h3>
                        <div class="image-box">
                            <img src="$OriginalPNG.URL" alt="Original PNG">
                        </div>
                        <div class="mt-2 small text-muted">
                            <code class="d-block text-break">$OriginalPNG.Filename</code>
                            $OriginalPNG.Dimensions
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <%-- Draft images comparison --%>
    <% if $HasDraftImages %>
    <div class="col-6">
        <div class="mb-5">
            <div class="card mb-4">
                <div class="card-body py-2">
                    <small class="text-muted">
                        <strong>Test Images:</strong>
                        SVG: <code>$DraftSVGImage.Name</code> (ID: $DraftSVGImage.ID, $OriginalDraftSVG.Dimensions)
                        &nbsp;|&nbsp;
                        PNG: <code>$DraftPNGImage.Name</code> (ID: $DraftPNGImage.ID, $OriginalDraftPNG.Dimensions)
                    </small>
                </div>
            </div>
            <%-- Draft originals --%>
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <h3 class="h6 mb-3">Original Draft SVG <span class="badge bg-success">SVG</span></h3>
                            <div class="image-box">
                                <img src="$OriginalDraftSVG.URL" alt="Original Draft SVG">
                            </div>
                            <div class="mt-2 small text-muted">
                                <code class="d-block text-break">$OriginalDraftSVG.Filename</code>
                                $OriginalDraftSVG.Dimensions
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <h3 class="h6 mb-3">Original Draft PNG <span class="badge bg-primary">PNG</span></h3>
                            <div class="image-box">
                                <img src="$OriginalDraftPNG.URL" alt="Original Draft PNG">
                            </div>
                            <div class="mt-2 small text-muted">
                                <code class="d-block text-break">$OriginalDraftPNG.Filename</code>
                                $OriginalDraftPNG.Dimensions
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <% end_if %>

</div>

<div class="row">
    <%-- Published images comparison --%>
    <div class="col-6 mb-5">
        <div class="bg-dark text-white px-3 py-2 rounded-top fw-semibold">Published Images Comparison</div>
        <div class="table-responsive">
            <table class="table table-bordered mb-0 bg-white">
                <thead class="table-light">
                    <tr>
                        <th style="width: 200px;">Method</th>
                        <th class="text-center">SVG Result</th>
                        <th class="text-center">PNG Result</th>
                    </tr>
                </thead>
                <tbody>
                    <% loop $Comparisons %>
                    <tr<% if $IsChained %> class="chained"<% end_if %>>
                        <td class="font-monospace small bg-light">
                            $Label
                            <% if $IsChained %><br><span class="badge bg-secondary">chained</span><% end_if %>
                        </td>
                        <% if $Error %>
                            <td colspan="2" class="text-danger small bg-danger-subtle">Error: $Error</td>
                        <% else %>
                            <td class="result-cell text-center">
                                <% if $SVG %>
                                    <div class="image-box"><img src="$SVG.URL" alt="$Label SVG"></div>
                                    <div class="mt-1 small text-muted">
                                        <code class="d-block text-break small">$SVG.Filename</code>
                                        $SVG.Dimensions
                                    </div>
                                <% else %>
                                    <span class="text-muted fst-italic">No result</span>
                                <% end_if %>
                            </td>
                            <td class="result-cell text-center">
                                <% if $PNG %>
                                    <div class="image-box"><img src="$PNG.URL" alt="$Label PNG"></div>
                                    <div class="mt-1 small text-muted">
                                        <code class="d-block text-break small">$PNG.Filename</code>
                                        $PNG.Dimensions
                                    </div>
                                <% else %>
                                    <span class="text-muted fst-italic">No result</span>
                                <% end_if %>
                            </td>
                        <% end_if %>
                    </tr>
                    <% end_loop %>
                </tbody>
            </table>
        </div>
    </div>

    <%-- Draft images comparison --%>
    <% if $HasDraftImages %>
        <div class="col-6 mb-5">
            <div class="text-white px-3 py-2 rounded-top fw-semibold" style="background: linear-gradient(135deg, #6f42c1, #9b59b6);">Draft/Unpublished Images (Protected Assets)</div>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 bg-white">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 200px;">Method</th>
                            <th class="text-center">Draft SVG Result</th>
                            <th class="text-center">Draft PNG Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <% loop $DraftComparisons %>
                        <tr<% if $IsChained %> class="chained"<% end_if %>>
                            <td class="font-monospace small bg-light">
                                $Label
                                <% if $IsChained %><br><span class="badge bg-secondary">chained</span><% end_if %>
                            </td>
                            <% if $Error %>
                                <td colspan="2" class="text-danger small bg-danger-subtle">Error: $Error</td>
                            <% else %>
                                <td class="result-cell text-center">
                                    <% if $SVG %>
                                        <div class="image-box"><img src="$SVG.URL" alt="$Label Draft SVG"></div>
                                        <div class="mt-1 small text-muted">
                                            <code class="d-block text-break small">$SVG.Filename</code>
                                            $SVG.Dimensions
                                        </div>
                                    <% else %>
                                        <span class="text-muted fst-italic">No result</span>
                                    <% end_if %>
                                </td>
                                <td class="result-cell text-center">
                                    <% if $PNG %>
                                        <div class="image-box"><img src="$PNG.URL" alt="$Label Draft PNG"></div>
                                        <div class="mt-1 small text-muted">
                                            <code class="d-block text-break small">$PNG.Filename</code>
                                            $PNG.Dimensions
                                        </div>
                                    <% else %>
                                        <span class="text-muted fst-italic">No result</span>
                                    <% end_if %>
                                </td>
                            <% end_if %>
                        </tr>
                        <% end_loop %>
                    </tbody>
                </table>
            </div>
        </div>
    <% end_if %>
</div>

<% end_if %>

</div>
</body>
</html>
