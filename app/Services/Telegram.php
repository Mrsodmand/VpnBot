<?php

namespace App\Services;

class Telegram
{
    protected string $token;
    protected string $baseUrl;

    public function __construct(string $token)
    {

        $this->token = $token;
        $this->baseUrl = "https://api.telegram.org/bot{$token}/";
    }

    /**
     * ارسال درخواست عمومی به تلگرام
     */

    protected function request(string $method, array $params = [])
    {
        $ch = curl_init($this->baseUrl . $method);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => $params,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'ok' => false,
                'error' => $error
            ];
        }

        curl_close($ch);

        return json_decode($response, true);
    }

    // =====================
    // 📩 Messages
    // =====================


    public function sendMessage(array $params)
    {
//        Example
//        $tg->sendMessage([
//            'chat_id' => $chat_id, // آیدی کاربر یا گروه
//            'text' => 'سلام 👋 پیام تست', // متن پیام
//
//            'parse_mode' => 'HTML', // نوع فرمت: HTML یا MarkdownV2
//            'disable_web_page_preview' => false, // پیش‌نمایش لینک‌ها خاموش/روشن
//            'disable_notification' => false, // پیام بی‌صدا ارسال شود یا نه
//            'protect_content' => false, // جلوگیری از فوروارد/سیو شدن پیام
//
//            'reply_to_message_id' => $message_id, // جواب دادن به یک پیام خاص
//            'allow_sending_without_reply' => true, // اگر پیام نبود خطا نده
//
//            'reply_markup' => json_encode([ // دکمه‌ها
//                'inline_keyboard' => [
//                    [
//                        ['text' => 'دکمه 1', 'callback_data' => 'btn1'],
//                        ['text' => 'دکمه 2', 'callback_data' => 'btn2']
//                    ]
//                ]
//            ])
//        ]);

        return $this->request('sendMessage', $params);
    }

    public function editMessage(array $params)
    {
//        Example
//        $tg->editMessage([
//            'chat_id' => $chat_id, // آیدی چت
//            'message_id' => $message_id, // پیام مورد نظر برای ویرایش
//            'text' => '✏️ متن جدید', // متن جدید پیام
//
//            'parse_mode' => 'HTML', // فرمت متن
//            'disable_web_page_preview' => true, // مخفی کردن preview لینک
//
//            'reply_markup' => json_encode([ // تغییر دکمه‌ها
//                'inline_keyboard' => [
//                    [
//                        ['text' => 'آپدیت شد ✔️', 'callback_data' => 'ok']
//                    ]
//                ]
//            ])
//        ]);
        return $this->request('editMessageText', $params);
    }

    public function deleteMessage(array $params)
    {
//        Example
//        $tg->deleteMessage([
//            'chat_id' => $chat_id, // چت
//            'message_id' => $message_id // پیام برای حذف
//        ]);
        return $this->request('deleteMessage', $params);
    }

    public function forwardMessage(array $params)
    {
//        Example
//        $tg->forwardMessage([
//            'chat_id' => $chat_id, // مقصد
//            'from_chat_id' => 987654321, // چت مبدا
//            'message_id' => 5, // پیام مورد نظر
//
//            'disable_notification' => false, // بی‌صدا بودن
//            'protect_content' => false // جلوگیری از فوروارد دوباره
//        ]);

        return $this->request('forwardMessage', $params);
    }

    // =====================
    // 📷 Media
    // =====================

    public function sendPhoto(array $params)
    {
//        Example
//        $tg->sendPhoto([
//            'chat_id' => $chat_id, // مقصد
//            'photo' => 'https://example.com/image.jpg', // لینک عکس یا file_id
//
//            'caption' => '🖼️ توضیح عکس', // متن زیر عکس
//            'parse_mode' => 'HTML', // فرمت متن
//
//            'has_spoiler' => false, // محو بودن عکس (blur)
//            'disable_notification' => false, // بی‌صدا بودن
//            'protect_content' => false, // جلوگیری از فوروارد
//
//            'reply_markup' => json_encode([ // دکمه زیر عکس
//                'inline_keyboard' => [
//                    [
//                        ['text' => 'لینک', 'url' => 'https://example.com']
//                    ]
//                ]
//            ])
//        ]);
        return $this->request('sendPhoto', $params);
    }

    public function sendVideo(array $params)
    {
//        Example
//        $tg->sendVideo([
//            'chat_id' => $chat_id, // مقصد
//            'video' => 'https://example.com/video.mp4', // لینک ویدیو
//
//            'caption' => '🎥 ویدیو تست', // توضیح ویدیو
//            'parse_mode' => 'HTML', // فرمت متن
//
//            'supports_streaming' => true, // پخش آنلاین
//            'duration' => 90, // مدت ویدیو (ثانیه)
//            'width' => 1280, // عرض
//            'height' => 720, // ارتفاع
//
//            'disable_notification' => false, // بی‌صدا بودن
//            'protect_content' => false // جلوگیری از فوروارد
//        ]);
        return $this->request('sendVideo', $params);
    }

    public function sendDocument(array $params)
    {
//        Example
//        $tg->sendDocument([
//            'chat_id' => $chat_id, // مقصد
//            'document' => 'https://example.com/file.pdf', // فایل
//
//            'caption' => '📄 فایل PDF', // توضیح فایل
//            'parse_mode' => 'HTML', // فرمت متن
//
//            'disable_content_type_detection' => false, // تشخیص نوع فایل
//            'disable_notification' => false, // بی‌صدا
//            'protect_content' => false // جلوگیری از فوروارد
//        ]);
        return $this->request('sendDocument', $params);
    }

    // =====================
    // 📌 Utilities
    // =====================

    public function answerCallback(array $params)
    {
//        Example
//        $tg->answerCallback([
//            'callback_query_id' => $callback_id, // آیدی کلیک روی دکمه
//
//            'text' => 'عملیات انجام شد ✅', // پیام پاپ‌آپ
//
//            'show_alert' => false, // false = toast بالا | true = alert وسط صفحه
//            'cache_time' => 0, // مدت کش پاسخ
//
//            'url' => null // ریدایرکت (اختیاری)
//        ]);
        return $this->request('answerCallbackQuery', $params);
    }

    public function getChatMember(array $params)
    {
//        Example
//        $res = $tg->getChatMember([
//            'chat_id' => '@your_channel', // کانال یا گروه
//            'user_id' => $chat_id // کاربر مورد بررسی
//        ]);
//
//        $status = $res['result']['status'] ?? null; // وضعیت عضویت
//
//        if (in_array($status, ['member', 'administrator', 'creator'])) {
//            echo "عضو است ✅";
//        } else {
//            echo "عضو نیست ❌";
//        }
        return $this->request('getChatMember', $params);
    }

    public function pinMessage(array $params)
    {
//        Example
//        $tg->pinMessage([
//            'chat_id' => $chat_id, // چت
//            'message_id' => $message_id, // پیام برای پین
//
//            'disable_notification' => true // بدون نوتیفیکیشن پین شود
//        ]);
        return $this->request('pinChatMessage', $params);
    }

    public function setWebhook(array $params)
    {
        /**
         * Example:
         * $tg->setWebhook([
         *     'url' => 'https://example.com/api/telegram/webhook',
         *     'secret_token' => 'my-secret-token', // (اختیاری ولی پیشنهادی)
         *     'max_connections' => 40,
         *     'allowed_updates' => ['message', 'callback_query'],
         *     'drop_pending_updates' => true
         * ]);
         */

        return $this->request('setWebhook', $params);
    }

    public function deleteWebhook(array $params = [])
    {
        /**
         * Example:
         * $tg->deleteWebhook([
         *     'drop_pending_updates' => true
         * ]);
         */

        return $this->request('deleteWebhook', $params);
    }

    public function getUpdates(array $params = [])
    {
        /**
         * Example:
         * $tg->getUpdates([
         *     'offset' => 123456789,
         *     'limit' => 100,
         *     'timeout' => 0,
         *     'allowed_updates' => ['message', 'callback_query']
         * ]);
         */

        return $this->request('getUpdates', $params);
    }

    // =====================
// 📥 Download Files
// =====================

    public function getFile(array $params)
    {
        return $this->request('getFile', $params);
    }

    public function downloadFileById(string $fileId, string $savePath): array
    {
        $file = $this->getFile([
            'file_id' => $fileId
        ]);

        if (!($file['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $file['description'] ?? 'File not found'
            ];
        }

        $filePath = $file['result']['file_path'] ?? null;

        if (!$filePath) {
            return [
                'ok' => false,
                'error' => 'file_path not found'
            ];
        }

        $fileUrl = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";

        $dir = dirname($savePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = file_get_contents($fileUrl);

        if ($content === false) {
            return [
                'ok' => false,
                'error' => 'Download failed'
            ];
        }

        file_put_contents($savePath, $content);

        return [
            'ok' => true,
            'path' => $savePath,
            'telegram_file_path' => $filePath,
            'url' => $fileUrl
        ];
    }
}
