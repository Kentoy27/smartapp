<?php

namespace App\Support;

/**
 * The official WFP (Work and Financial Plan) template.
 *
 * Like the OPCRF template, the workbook ships under storage/forms/ and is
 * served through an authenticated route — never from public/. There is no
 * term gate here: the WFP template is always the complete workbook.
 */
class WfpTemplate
{
    /**
     * The shipped template's path, wherever the app runs from.
     */
    public static function templatePath(): string
    {
        return storage_path('forms/'.(string) config('wfp.template_file', 'WFP-Template.xlsx'));
    }

    public static function exists(): bool
    {
        return is_file(self::templatePath());
    }

    /**
     * The raw workbook bytes, exactly as stored (structure preserved).
     */
    public static function bytes(): string
    {
        return (string) file_get_contents(self::templatePath());
    }

    /**
     * The friendly name shown on the card and used as the download filename.
     */
    public static function fileName(): string
    {
        return (string) config('wfp.file_name', 'Work and Financial Plan.xlsx');
    }

    public static function description(): string
    {
        return (string) config('wfp.description', '');
    }

    /**
     * Inspect the shipped template's table layout: which sheet the table is on,
     * the header row, and the resolved column letters (including month columns
     * when enabled).
     *
     * This is the de-risking tool behind the export: a mis-detected column is
     * pinned in config/wfp.php (`'column' => 'C'`) rather than guessed at. The
     * `wfp:inspect-template` command prints exactly this.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the template is missing or has no
     *                           recognisable header row
     */
    public static function inspect(): array
    {
        if (! self::exists()) {
            throw new \RuntimeException('The WFP template could not be found at '.self::templatePath().'.');
        }

        return WfpTemplateFiller::locate(WfpTemplateFiller::readParts(self::bytes()));
    }
}
