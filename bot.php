<?php
echo "Я — bot_longpoll.php — я живой! " . date('H:i:s') . "\n";
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Telegram\Bot\Api;

$telegramToken = '8209966982:AAEqlRYGxNiZEZetCNz36IwD1ILwnGLwrv4';
$excelFile     = 'Book 2.xlsx';
$cacheFile     = __DIR__ . '/schedule_cache.json';

$telegram = new Api($telegramToken);

// Кэширование Excel
function getTableData(): array
{
    global $cacheFile, $excelFile;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    try {
        $spreadsheet = IOFactory::load($excelFile);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $data = ['orig' => $rows, 'norm' => []];
        foreach ($rows as $row) {
            $normRow = array_map(function($cell) {
                $cell = trim($cell ?? '');
                $cell = preg_replace('/\s+/u', ' ', $cell);
                return mb_strtoupper($cell, 'UTF-8');
            }, $row);
            $data['norm'][] = $normRow;
        }

        file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    } catch (Exception $e) {
        return ['orig' => [], 'norm' => []];
    }
}

function findSchedule(string $text): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return [];

    $words = array_filter(explode(' ', mb_strtoupper($text, 'UTF-8')));
    $data = getTableData();

    $normRows = $data['norm'];
    $origRows = $data['orig'];
    $matches = [];

    foreach ($normRows as $i => $row) {
        if ($i === 0) continue;
        $rowText = implode(' ', $row);

        $found = 0;
        foreach ($words as $w) {
            if (strpos($rowText, $w) !== false) $found++;
        }
        if ($found > 0) {
            $matches[] = $i;
        }
    }

    if (empty($matches)) return [];

    // Находим колонки с датой и временем
    $header = $normRows[0] ?? [];
    $dateCol = $timeCol = -1;
    foreach ($header as $i => $h) {
        if (in_array($h, ['ДАТА', 'DATE', 'ДЕНЬ'])) $dateCol = $i;
        if (in_array($h, ['ВРЕМЯ', 'ЧАСЫ', 'СМЕНА', 'TIME', 'ВРЕМЯ ВЫХОДА'])) $timeCol = $i;
    }
    if ($dateCol === -1 || $timeCol === -1) return [];

    $result = [];
    foreach ($matches as $i) {
        $row = $origRows[$i];
        $date = trim($row[$dateCol] ?? '');
        $time = trim($row[$timeCol] ?? '');
        if ($date && $time) {
            $result[] = ['date' => $date, 'time' => $time];
        }
    }

    usort($result, fn($a,$b) => strtotime($a['date']) <=> strtotime($b['date']));
    return $result;
}

// === LONGPOLL ===
echo "Умный бот запущен! Нажми Ctrl+C чтобы остановить.\n";

$offset = 0;
while (true) {
    try {
        $updates = $telegram->getUpdates(['offset' => $offset, 'timeout' => 30]);
        foreach ($updates as $update) {
            $updateId = $update->getUpdateId();
            if ($updateId >= $offset) $offset = $updateId + 1;

            $msg = $update->getMessage();
            if (!$msg) continue;

            $chatId = $msg->getChat()->getId();
            $text   = trim($msg->getText() ?? '');

            if ($text === '/start') {
                $kb = ['keyboard' => [['Моё расписание']], 'resize_keyboard' => true];
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Привет! Напиши имя или фамилию — я найду твоё расписание!",
                    'reply_markup' => json_encode($kb)
                ]);
                continue;
            }

            if (stripos($text, 'расписание') !== false) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Просто напиши своё имя или фамилию"
                ]);
                continue;
            }

            $sched = findSchedule($text);

            if (empty($sched)) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Ничего не нашёл по «{$text}»"
                ]);
            } else {
                $out = "*Твоё расписание:*\n\n";
                foreach ($sched as $s) {
                    $d = date('d.m.Y', strtotime($s['date']));
                    $wd = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($s['date']))];
                    $out .= "• {$d} ({$wd}) — {$s['time']}\n";
                }
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $out,
                    'parse_markup' => 'Markdown'
                ]);
            }
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
        sleep(2);
    }
}