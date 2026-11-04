<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $preview['file_name'] }} — Document preview</title>
    <style>
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; }
        body { margin: 0; padding: 18px; overflow: hidden; color: #1e293b; background: #f8fafc; font: 14px/1.5 system-ui, sans-serif; }
        .preview { width: 100%; height: 100%; min-height: 0; margin: 0 auto; padding: 18px; overflow: auto; overscroll-behavior: contain; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        h1 { margin: 0 0 14px; font-size: 18px; overflow-wrap: anywhere; }
        h2 { margin: 18px 0 8px; font-size: 15px; }
        .sheet-wrap { max-width: 100%; overflow: auto; margin: 12px 0 22px; }
        table { border-collapse: collapse; min-width: 100%; }
        td { min-width: 80px; max-width: 420px; padding: 6px 9px; border: 1px solid #cbd5e1; vertical-align: top; white-space: pre-wrap; overflow-wrap: anywhere; }
        .document-table td { min-width: 100px; }
        p { white-space: pre-wrap; overflow-wrap: anywhere; }
        pre { white-space: pre-wrap; overflow-wrap: anywhere; }
        .note { padding: 12px; border-radius: 7px; color: #475569; background: #f1f5f9; }
        .slide { min-height: 160px; margin: 12px 0; padding: 22px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; box-shadow: 0 2px 6px #0f172a12; }
        @media (max-width: 600px) { body { padding: 8px; } .preview { padding: 12px; } td { min-width: 64px; } }
    </style>
</head>
<body>
    <main class="preview">
        <h1>{{ $preview['file_name'] }}</h1>

        @if ($preview['kind'] === 'spreadsheet')
            @foreach ($preview['sheets'] as $sheet)
                <h2>{{ $sheet['name'] }}</h2>
                <div class="sheet-wrap">
                    <table>
                        <tbody>
                            @foreach ($sheet['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($sheet['truncated'])
                    <p class="note">Preview limited to the first 500 rows and 40 columns.</p>
                @endif
            @endforeach
        @elseif ($preview['kind'] === 'word')
            @foreach ($preview['paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            @foreach ($preview['tables'] as $table)
                <div class="sheet-wrap">
                    <table class="document-table">
                        <tbody>
                            @foreach ($table as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @elseif ($preview['kind'] === 'presentation')
            @foreach ($preview['slides'] as $slide)
                <section class="slide">
                    <h2>{{ $slide['name'] }}</h2>
                    @foreach ($slide['paragraphs'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </section>
            @endforeach
        @elseif ($preview['kind'] === 'text')
            <pre>{{ $preview['text'] }}</pre>
        @else
            <p class="note">{{ $preview['message'] }}</p>
        @endif
    </main>
</body>
</html>
