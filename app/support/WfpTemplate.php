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
        return storage_path('forms/'.(string) config('wfp.template_file', 'WFP-TEMPLATE.xlsx'));
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
        return (string) config('wfp.file_name', 'WFP.xlsx');
    }

    public static function description(): string
    {
        return (string) config('wfp.description', '');
    }
}
