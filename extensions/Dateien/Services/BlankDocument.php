<?php

namespace Extensions\Dateien\Services;

use RuntimeException;
use ZipArchive;

/** Empty starter files for "Neue Datei" (valid minimal Office Open XML packages, plain text, Markdown) */
class BlankDocument
{
    /** Extension => label */
    public const TYPES = ['docx' => 'Dokument', 'xlsx' => 'Tabelle', 'pptx' => 'Präsentation', 'txt' => 'Textdatei', 'md' => 'Markdown'];

    public const OFFICE = ['docx', 'xlsx', 'pptx'];

    public static function bytes(string $extension): string
    {
        return match ($extension) {
            'txt', 'md' => '',
            'docx', 'xlsx', 'pptx' => self::package($extension),
            default => throw new RuntimeException('Unknown type'),
        };
    }

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    private static function rels(array $items): string
    {
        $out = self::HEAD.'<Relationships xmlns="'.self::NS_PKG.'">';
        foreach ($items as $id => [$type, $target]) {
            $out .= '<Relationship Id="'.$id.'" Type="'.self::NS_R.'/'.$type.'" Target="'.$target.'"/>';
        }

        return $out.'</Relationships>';
    }

    private static function package(string $extension): string
    {
        $o = 'application/vnd.openxmlformats-officedocument.';
        $files = match ($extension) {
            'docx' => [
                'types' => [['word/document.xml', $o.'wordprocessingml.document.main+xml']],
                'root' => 'word/document.xml',
                'parts' => ['word/document.xml' => self::HEAD.'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/><w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1134" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>'],
            ],
            'xlsx' => [
                'types' => [['xl/workbook.xml', $o.'spreadsheetml.sheet.main+xml'], ['xl/worksheets/sheet1.xml', $o.'spreadsheetml.worksheet+xml']],
                'root' => 'xl/workbook.xml',
                'parts' => [
                    'xl/workbook.xml' => self::HEAD.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="'.self::NS_R.'"><sheets><sheet name="Tabelle1" sheetId="1" r:id="rId1"/></sheets></workbook>',
                    'xl/_rels/workbook.xml.rels' => self::rels(['rId1' => ['worksheet', 'worksheets/sheet1.xml']]),
                    'xl/worksheets/sheet1.xml' => self::HEAD.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
                ],
            ],
            default => self::presentation($o),
        };

        $path = tempnam(sys_get_temp_dir(), 'blank');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create archive');
        }
        $types = self::HEAD.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>';
        foreach ($files['types'] as [$part, $type]) {
            $types .= '<Override PartName="/'.$part.'" ContentType="'.$type.'"/>';
        }
        $zip->addFromString('[Content_Types].xml', $types.'</Types>');
        $zip->addFromString('_rels/.rels', self::rels(['rId1' => ['officeDocument', $files['root']]]));
        foreach ($files['parts'] as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private static function presentation(string $o): array
    {
        $p = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="'.self::NS_R.'" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $tree = '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree>';
        $colors = '';
        foreach (['dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '1F497D', 'lt2' => 'EEECE1', 'accent1' => '4F81BD', 'accent2' => 'C0504D', 'accent3' => '9BBB59', 'accent4' => '8064A2', 'accent5' => '4BACC6', 'accent6' => 'F79646', 'hlink' => '0000FF', 'folHlink' => '800080'] as $name => $hex) {
            $colors .= "<a:$name><a:srgbClr val=\"$hex\"/></a:$name>";
        }
        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = '<a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>';
        $theme = self::HEAD.'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office"><a:themeElements>'
            .'<a:clrScheme name="Office">'.$colors.'</a:clrScheme>'
            .'<a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            .'<a:fmtScheme name="Office"><a:fillStyleLst>'.str_repeat($fill, 3).'</a:fillStyleLst><a:lnStyleLst>'.str_repeat($line, 3).'</a:lnStyleLst><a:effectStyleLst>'.str_repeat('<a:effectStyle><a:effectLst/></a:effectStyle>', 3).'</a:effectStyleLst><a:bgFillStyleLst>'.str_repeat($fill, 3).'</a:bgFillStyleLst></a:fmtScheme>'
            .'</a:themeElements></a:theme>';

        return [
            'types' => [
                ['ppt/presentation.xml', $o.'presentationml.presentation.main+xml'],
                ['ppt/slideMasters/slideMaster1.xml', $o.'presentationml.slideMaster+xml'],
                ['ppt/slideLayouts/slideLayout1.xml', $o.'presentationml.slideLayout+xml'],
                ['ppt/slides/slide1.xml', $o.'presentationml.slide+xml'],
                ['ppt/theme/theme1.xml', $o.'theme+xml'],
            ],
            'root' => 'ppt/presentation.xml',
            'parts' => [
                'ppt/presentation.xml' => self::HEAD."<p:presentation $p><p:sldMasterIdLst><p:sldMasterId id=\"2147483648\" r:id=\"rId1\"/></p:sldMasterIdLst><p:sldIdLst><p:sldId id=\"256\" r:id=\"rId2\"/></p:sldIdLst><p:sldSz cx=\"12192000\" cy=\"6858000\"/><p:notesSz cx=\"6858000\" cy=\"9144000\"/></p:presentation>",
                'ppt/_rels/presentation.xml.rels' => self::rels(['rId1' => ['slideMaster', 'slideMasters/slideMaster1.xml'], 'rId2' => ['slide', 'slides/slide1.xml'], 'rId3' => ['theme', 'theme/theme1.xml']]),
                'ppt/slideMasters/slideMaster1.xml' => self::HEAD."<p:sldMaster $p><p:cSld>$tree</p:cSld><p:clrMap bg1=\"lt1\" tx1=\"dk1\" bg2=\"lt2\" tx2=\"dk2\" accent1=\"accent1\" accent2=\"accent2\" accent3=\"accent3\" accent4=\"accent4\" accent5=\"accent5\" accent6=\"accent6\" hlink=\"hlink\" folHlink=\"folHlink\"/><p:sldLayoutIdLst><p:sldLayoutId id=\"2147483649\" r:id=\"rId1\"/></p:sldLayoutIdLst></p:sldMaster>",
                'ppt/slideMasters/_rels/slideMaster1.xml.rels' => self::rels(['rId1' => ['slideLayout', '../slideLayouts/slideLayout1.xml'], 'rId2' => ['theme', '../theme/theme1.xml']]),
                'ppt/slideLayouts/slideLayout1.xml' => self::HEAD."<p:sldLayout $p type=\"blank\"><p:cSld name=\"Leer\">$tree</p:cSld></p:sldLayout>",
                'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => self::rels(['rId1' => ['slideMaster', '../slideMasters/slideMaster1.xml']]),
                'ppt/slides/slide1.xml' => self::HEAD."<p:sld $p><p:cSld>$tree</p:cSld></p:sld>",
                'ppt/slides/_rels/slide1.xml.rels' => self::rels(['rId1' => ['slideLayout', '../slideLayouts/slideLayout1.xml']]),
                'ppt/theme/theme1.xml' => $theme,
            ],
        ];
    }
}
