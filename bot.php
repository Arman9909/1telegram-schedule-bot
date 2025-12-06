<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Telegram\Bot\Api;

// === НАСТРОЙКИ ===
$telegramToken = '8209966982:AAGL6z6ivxS3qtkZlUx0TPHPpiYD0HR5BX0';
$excelFile     = 'Book 2.xlsx';

// === ФУНКЦИЯ ПОИСКА РАСПИСАНИЯ ===
function getScheduleForPerson(string $firstName, string $lastName, string $file): array
{
    $fullName = mb_strtoupper(trim($firstName . ' ' . $lastName));

    try {
        $spreadsheet = IOFactory::load($file);
    } catch (Exception $e) {
        return [];
    }

    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray();
    if (empty($rows)) return [];

    $headers = array_map('mb_strtoupper', array_map('trim', $rows[0]));

    $colName    = array_search('ИМЯ', $headers);
    $colSurname = array_search('ФАМИЛИЯ', $headers);
    $colDate    = array_search('ДАТА', $headers);
    $colTime    = array_search('ВРЕМЯ ВЫХОДА', $headers);

    if ($colName === false || $colSurname === false || $colDate === false || $colTime === false) {
        return [];
    }

    $result = [];
    foreach ($rows as $index => $row) {
        if ($index === 0) continue;

        $name    = mb_strtoupper(trim($row[$colName] ?? ''));
        $surname = mb_strtoupper(trim($row[$colSurname] ?? ''));
        $full    = $name . ' ' . $surname;

        if ($full === $fullName) {
            $date = trim($row[$colDate] ?? '');
            $time = trim($row[$colTime] ?? '');
            if ($date) {
                $result[] = ['date' => $date, 'time' => $time];
            }
        }
    }

    usort($result, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
    return $result;
}

// === ОСНОВНАЯ ЛОГИКА БОТА ===
try {
    $telegram = new Api($telegramToken);
    $update   = $telegram->getWebhookUpdate();  // это Illuminate Collection

    // Правильное получение message, chat_id и текста в этой библиотеке
    $message = $update['message'] ?? null;
    if (!$message) exit;

    $chatId = $message['chat']['id'] ?? null;
    if (!$chatId) exit;

    $text = trim($message['text'] ?? '');

    if ($text === '/start') {
        $keyboard = [
            'keyboard'          => [['Показать моё расписание']],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];

        $telegram->sendMessage([
            'chat_id'      => $chatId,
            'text'         => "Привет! Нажми кнопку ниже или введи своё имя и фамилию (например: Иван Иванов)",
            'reply_markup' => json_encode($keyboard),
        ]);
        exit;
    }

    if (mb_stripos($text, 'показать моё расписание') !== false || $text === '/schedule') {
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text'    => "Введи имя и фамилию точно как в таблице (например: Иван Иванов):",
        ]);
        exit;
    }

    if (preg_match('/^([А-ЯЁа-яёA-Za-z-]+)\s+([А-ЯЁа-яёA-Za-z-]+)$/u', $text, $m)) {
        $firstName = $m[1];
        $lastName  = $m[2];

        $schedule = getScheduleForPerson($firstName, $lastName, $excelFile);

        if (empty($schedule)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text'    => "Ничего не найдено по «{$firstName} {$lastName}». Проверь написание и регистр.",
            ]);
        } else {
            $response = "*Твоё расписание:*\n\n";
            foreach ($schedule as $item) {
                $date = date('d.m.Y', strtotime($item['date']));
                $days = ['Monday' => 'Понедельник', 'Tuesday' => 'Вторник', 'Wednesday' => 'Среда',
                         'Thursday' => 'Четверг', 'Friday' => 'Пятница', 'Saturday' => 'Суббота', 'Sunday' => 'Воскресенье'];
                $dayRu = $days[date('l', strtotime($item['date']))] ?? date('l', strtotime($item['date']));
                $response .= "📅 {$date} ({$dayRu}) — 🕑 {$item['time']}\n";
            }

            $telegram->sendMessage([
                'chat_id'    => $chatId,
                'text'       => $response,
                'parse_mode' => 'Markdown',
            ]);
        }
    } else {
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text'    => "Пожалуйста, введи имя и фамилию через пробел (например: Иван Иванов)",
        ]);
    }
} catch (Exception $e) {
    error_log('Bot error: ' . $e->getMessage());
}

echo "OK";
