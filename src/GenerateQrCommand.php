<?php

namespace App;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'qr:generate')]
class GenerateQrCommand extends Command
{
    private const RASTER = [
        ['x' => 20, 'y' => 15],
        ['x' => 80, 'y' => 15],
        ['x' => 140, 'y' => 15],
        ['x' => 20, 'y' => 102],
        ['x' => 80, 'y' => 102],
        ['x' => 140, 'y' => 102],
        ['x' => 20, 'y' => 189],
        ['x' => 80, 'y' => 189],
        ['x' => 140, 'y' => 189],
    ];

    private const TEXT_OFFSET = ['x' => 0, 'y' => 0];
    private const CODE_OFFSET = ['x' => 0, 'y' => 5];
    private const IMAGE_OFFSET = ['x' => 25 - (25 / 3 * 2), 'y' => 55];

    private const STYLE = [
        'border' => 0,
        'vpadding' => 'auto',
        'hpadding' => 'auto',
        'fgcolor' => [0, 0, 0],
        'bgcolor' => false, //[255, 255, 255]
        'module_width' => 1, // width of a single module in points
        'module_height' => 1 // height of a single module in points
    ];

    private const FRONT_FONT_SIZE = 12;
    private const BACK_FONT_SIZE = 8;

    private \TCPDF $pdf;
    private int $qrCount;
    private string $logoFile;

    public function __construct()
    {
        parent::__construct();
        $this->qrCount = \count(self::RASTER);
    }

    protected function configure()
    {
        $this
            ->addArgument('qr-list', InputArgument::REQUIRED, 'Spreadsheet or CSV containing the QR codes')
            ->addArgument('logo-file', InputArgument::REQUIRED, 'Filename of the logo')
            ->addArgument('pdf-file', InputArgument::REQUIRED, 'Filename of the generated PDF file')
            ->addOption('headers', null, InputOption::VALUE_REQUIRED, 'Number of rows that are considered headers', default: '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qrList = $input->getArgument('qr-list');
        $this->logoFile = $input->getArgument('logo-file');
        $pdfFile = $input->getArgument('pdf-file');

        $headerRows = $input->getOption('headers');
        if (!\ctype_digit($headerRows) || $headerRows < 0) {
            throw new \RuntimeException('--headers needs to be a positive integer');
        }

        $spreadsheet = IOFactory::load($qrList);

        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();

        $this->initialisePdf();

        for ($row = $headerRows + 1; $row <= $highestRow; ++$row) {
            $url = $worksheet->getCell('A' . $row);
            $id = $worksheet->getCell('B' . $row);

            $io->writeln("ID: {$id}; URL: {$url}");

            $this->createQrCode($id, $url);
        }

        \file_put_contents($pdfFile, $this->pdf->Output($pdfFile, 'S'));

        return 0;
    }

    private function initialisePdf(): void
    {
        $this->pdf = new \TCPDF(\PDF_PAGE_ORIENTATION, \PDF_UNIT, \PDF_PAGE_FORMAT, false, 'ISO-8859-1', false);
        $this->pdf->setDocInfoUnicode(true);

        $this->pdf->setCreator('QuestHunt');
        $this->pdf->setAuthor('QuestHunt');
        $this->pdf->setTitle('QuestHunt QR codes');
        $this->pdf->setSubject('QuestHunt QR codes');
        $this->pdf->setKeywords('QuestHunt QR codes');

        $this->pdf->setDefaultMonospacedFont(\PDF_FONT_MONOSPACED);

        $this->pdf->setMargins(0, 0, 0, 0);

        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);

        $this->pdf->setAutoPageBreak(true, \PDF_MARGIN_BOTTOM);

        $this->pdf->setImageScale(\PDF_IMAGE_SCALE_RATIO);

        $this->pdf->setLanguageArray([
            'a_meta_charset' => 'ISO-8859-1',
            'a_meta_dir' => 'ltr',
            'a_meta_language' => 'en',
            'w_page' => 'page',
        ]);
    }

    private function createQrCode(string $id, string $url): void
    {
        if ($this->qrCount == \count(self::RASTER)) {
            $this->createBackSide();
            $this->pdf->AddPage();

            $this->qrCount = 0;
        }

        $x = $this->qrCount % \count(self::RASTER);

        $this->pdf->setXY(self::RASTER[$x]['x'], self::RASTER[$x]['y']);
        $this->pdf->Cell(50, 81, 'QuestHunt', 1, 0, 'L', valign: 'T');

        $this->pdf->setXY(self::RASTER[$x]['x'], self::RASTER[$x]['y']);
        $this->pdf->Cell(50, 81, $id, 1, 0, 'R', valign: 'T');

        $this->pdf->write2DBarcode($url, 'QRCODE,H', self::RASTER[$x]['x'] + self::CODE_OFFSET['x'],
            self::RASTER[$x]['y'] + self::CODE_OFFSET['y'], 50, 50, self::STYLE, 'N');

        $this->pdf->Image($this->logoFile, self::RASTER[$x]['x'] + (int)self::IMAGE_OFFSET['x'],
            self::RASTER[$x]['y'] + (int)self::IMAGE_OFFSET['y'], (int)(25 / 3 * 4), 25, resize: true);

        $this->qrCount++;
    }

    private function createBackSide(): void
    {
        $this->pdf->AddPage();

        $this->setFontSize(self::BACK_FONT_SIZE);

        foreach (self::RASTER as $raster) {
            $this->pdf->setXY($raster['x'], $raster['y']);
            $this->pdf->Cell(50, 81, '', 1);

            $this->pdf->StartTransform();
            $this->pdf->Rotate(270);
            $this->pdf->writeHTMLCell(
                50, 50,
                $raster['x'] + 55, $raster['y'] + 1,
                <<<'HTML'
                <h3>QuestHunt</h3>
                <p><b>Nodig:</b> QR code scanner op je smartphone.<br>
                Scan je eigen QR-code om in te loggen.</p>
                <p>Ga op zoek naar je target en scan de QR code van je target voor 10pt Wordt je zelf gescand, dan krijg je 5pt</p>
                <p>Meer informatie:<br>
                https://skenme.nl/questhunt</p>
                HTML,
                align: 'C',
            );
            $this->pdf->StopTransform();

            $this->pdf->Image($this->logoFile, $raster['x'] + (int)self::IMAGE_OFFSET['x'],
                $raster['y'] + (int)self::IMAGE_OFFSET['y'], (int)(25 / 3 * 4), 25, resize: true);
        }

        $this->setFontSize(self::FRONT_FONT_SIZE);
    }

    private function setFontSize(int $fontSize): void
    {
        $this->pdf->setFont('helvetica', size: $fontSize);
    }
}
