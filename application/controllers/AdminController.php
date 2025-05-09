<?php
namespace application\controllers;

require_once __DIR__ . '/../Model/DB.php';

use Application\Model\DB;

/**
 * کلاس مدیریت پنل ادمین
 */
class AdminController
{
    /**
     * شناسه کاربر
     * @var int
     */
    private $user_id;
    
    /**
     * سازنده
     * @param int $user_id شناسه کاربر
     */
    public function __construct($user_id)
    {
        $this->user_id = $user_id;
    }
    
    /**
     * بررسی دسترسی ادمین
     * @return bool
     */
    public function isAdmin()
    {
        try {
            // دریافت اطلاعات کاربر
            $user = DB::table('users')
                ->where('telegram_id', $this->user_id)
                ->first();
                
            if (!$user) {
                echo "کاربر با آیدی {$this->user_id} در دیتابیس یافت نشد!\n";
                return false;
            }
            
            // ادمین‌های اصلی
            $owner_ids = [286420965, 6739124921]; // افزودن مالک جدید
            if (in_array($this->user_id, $owner_ids)) {
                echo "ادمین اصلی با آیدی {$this->user_id} شناسایی شد!\n";
                return true;
            }
            
            // بررسی فیلد is_admin
            if (isset($user['is_admin']) && $user['is_admin'] === true) {
                return true;
            }
            
            // بررسی وضعیت ادمین (برای سازگاری با نسخه‌های قبلی)
            return in_array($user['type'], ['admin', 'owner']);
        } catch (\Exception $e) {
            error_log("Error in isAdmin: " . $e->getMessage());
            echo "خطا در بررسی دسترسی ادمین: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * بررسی دسترسی ادمین به قابلیت خاص
     * @param string $permission نام دسترسی
     * @return bool
     */
    public function hasPermission($permission)
    {
        // اگر ادمین اصلی است، تمام دسترسی‌ها را دارد
        $owner_ids = [286420965, 6739124921]; // مالکین اصلی ربات
        if (in_array($this->user_id, $owner_ids)) {
            return true;
        }
        
        // در غیر این صورت بررسی دسترسی‌های خاص
        try {
            // دریافت اطلاعات کاربر
            $user = DB::table('users')
                ->where('telegram_id', $this->user_id)
                ->first();
                
            if (!$user) {
                return false;
            }
            
            // دریافت دسترسی‌های کاربر
            $admin_permissions = DB::table('admin_permissions')
                ->where('user_id', $user['id'])
                ->first();
                
            if (!$admin_permissions) {
                return false;
            }
            
            // بررسی دسترسی خاص
            return isset($admin_permissions[$permission]) && $admin_permissions[$permission] === true;
        } catch (\Exception $e) {
            error_log("Error in hasPermission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * بررسی وضعیت فعال بودن ربات
     * @return bool
     */
    public function isBotActive()
    {
        try {
            // دریافت تنظیمات از دیتابیس
            $settings = DB::table('bot_settings')
                ->where('name', 'bot_active')
                ->first();
                
            if (!$settings) {
                // اگر تنظیمات موجود نبود، فرض بر فعال بودن ربات است
                return true;
            }
            
            return (bool)$settings['value'];
        } catch (\Exception $e) {
            error_log("Error in isBotActive: " . $e->getMessage());
            echo "خطا در بررسی وضعیت فعال بودن ربات: " . $e->getMessage() . "\n";
            // در صورت خطا، فرض بر این است که ربات فعال است
            return true;
        }
    }
    
    /**
     * ارسال پیام همگانی به تمام کاربران
     * 
     * @param string $message متن پیام همگانی
     * @param bool $includeStats آیا آمار ربات در پیام همگانی نمایش داده شود
     * @return array نتیجه عملیات
     */
    public function broadcastMessage($message, $includeStats = false)
    {
        try {
            // بررسی دسترسی‌های ادمین
            if (!$this->isAdmin() && !$this->hasPermission('can_send_broadcasts')) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای ارسال پیام همگانی را ندارید.'
                ];
            }
            
            // اگر نیاز به نمایش آمار باشد
            if ($includeStats) {
                $stats = $this->getBotStatistics();
                $message .= "\n\n📊 *آمار ربات:*\n";
                $message .= "• تعداد کل کاربران: {$stats['total_users']}\n";
                $message .= "• کاربران فعال در 24 ساعت گذشته: {$stats['active_users_today']}\n";
                $message .= "• تعداد بازی‌های انجام شده: {$stats['total_games']}\n";
                $message .= "• کاربران جدید امروز: {$stats['new_users_today']}";
            }
            
            // دریافت لیست کاربران
            $users = DB::table('users')->select('id', 'telegram_id')->get();
            $sentCount = 0;
            $failedCount = 0;
            
            // ارسال پیام به هر کاربر
            foreach ($users as $user) {
                try {
                    // چک کردن آیدی تلگرام
                    if (empty($user['telegram_id'])) {
                        $failedCount++;
                        continue;
                    }
                    
                    // ارسال پیام
                    $this->sendTelegramMessage($user['telegram_id'], $message);
                    $sentCount++;
                    
                    // کمی تأخیر برای جلوگیری از محدودیت‌های تلگرام
                    usleep(200000); // 0.2 ثانیه تأخیر
                } catch (\Exception $e) {
                    $failedCount++;
                    error_log("Failed to send broadcast to {$user['telegram_id']}: " . $e->getMessage());
                }
            }
            
            // ثبت در لاگ سیستم
            echo "پیام همگانی به {$sentCount} کاربر ارسال شد. {$failedCount} پیام ناموفق.\n";
            
            return [
                'success' => true,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'message' => "پیام با موفقیت به {$sentCount} کاربر ارسال شد."
            ];
            
        } catch (\Exception $e) {
            error_log("Error in broadcastMessage: " . $e->getMessage());
            echo "خطا در ارسال پیام همگانی: " . $e->getMessage() . "\n";
            
            return [
                'success' => false,
                'message' => "خطا در ارسال پیام همگانی: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * فوروارد پیام به همه کاربران
     *
     * @param int $fromChatId آیدی چت مبدا
     * @param int $messageId آیدی پیام مبدا
     * @return array نتیجه عملیات
     */
    public function forwardMessageToAll($fromChatId, $messageId)
    {
        try {
            // بررسی دسترسی‌های ادمین
            if (!$this->isAdmin() && !$this->hasPermission('can_send_broadcasts')) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای فوروارد همگانی را ندارید.'
                ];
            }
            
            // دریافت لیست کاربران
            $users = DB::table('users')->select('id', 'telegram_id')->get();
            $sentCount = 0;
            $failedCount = 0;
            
            // فوروارد پیام به هر کاربر
            foreach ($users as $user) {
                try {
                    // چک کردن آیدی تلگرام
                    if (empty($user['telegram_id'])) {
                        $failedCount++;
                        continue;
                    }
                    
                    // فوروارد پیام
                    $this->forwardTelegramMessage($user['telegram_id'], $fromChatId, $messageId);
                    $sentCount++;
                    
                    // کمی تأخیر برای جلوگیری از محدودیت‌های تلگرام
                    usleep(200000); // 0.2 ثانیه تأخیر
                } catch (\Exception $e) {
                    $failedCount++;
                    error_log("Failed to forward message to {$user['telegram_id']}: " . $e->getMessage());
                }
            }
            
            // ثبت در لاگ سیستم
            echo "پیام به {$sentCount} کاربر فوروارد شد. {$failedCount} فوروارد ناموفق.\n";
            
            return [
                'success' => true,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'message' => "پیام با موفقیت به {$sentCount} کاربر فوروارد شد."
            ];
            
        } catch (\Exception $e) {
            error_log("Error in forwardMessageToAll: " . $e->getMessage());
            echo "خطا در فوروارد همگانی: " . $e->getMessage() . "\n";
            
            return [
                'success' => false,
                'message' => "خطا در فوروارد همگانی: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * ارسال پیام تلگرام (متد کمکی)
     */
    private function sendTelegramMessage($chatId, $message, $keyboard = null)
    {
        // پارامترهای پایه
        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];
        
        // اضافه کردن کیبورد در صورت وجود
        if ($keyboard) {
            $params['reply_markup'] = $keyboard;
        }
        
        // ساخت URL برای API تلگرام
        $url = "https://api.telegram.org/bot" . $_ENV['TELEGRAM_TOKEN'] . "/sendMessage";
        
        // ارسال درخواست
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new \Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        $result = json_decode($response, true);
        
        if (!$result['ok']) {
            throw new \Exception('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));
        }
        
        return $result;
    }
    
    /**
     * فوروارد پیام تلگرام (متد کمکی)
     */
    private function forwardTelegramMessage($chatId, $fromChatId, $messageId)
    {
        // پارامترهای پایه
        $params = [
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId
        ];
        
        // ساخت URL برای API تلگرام
        $url = "https://api.telegram.org/bot" . $_ENV['TELEGRAM_TOKEN'] . "/forwardMessage";
        
        // ارسال درخواست
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new \Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        $result = json_decode($response, true);
        
        if (!$result['ok']) {
            throw new \Exception('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));
        }
        
        return $result;
    }
    
    /**
     * تنظیم وضعیت فعال یا غیرفعال بودن ربات
     * 
     * @param bool $status وضعیت جدید ربات (true = فعال، false = غیرفعال)
     * @return bool نتیجه عملیات
     */
    public function setBotStatus($status)
    {
        try {
            // بررسی آیا ردیف در دیتابیس وجود دارد
            $exists = DB::table('bot_settings')
                ->where('name', 'bot_active')
                ->exists();
                
            if ($exists) {
                // به‌روزرسانی
                DB::table('bot_settings')
                    ->where('name', 'bot_active')
                    ->update(['value' => $status ? '1' : '0']);
            } else {
                // ایجاد
                DB::table('bot_settings')->insert([
                    'name' => 'bot_active',
                    'value' => $status ? '1' : '0',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("Error in setBotStatus: " . $e->getMessage());
            echo "خطا در تنظیم وضعیت ربات: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * تغییر وضعیت فعال بودن ربات
     * @param bool $active وضعیت جدید
     * @return array
     */
    public function toggleBotStatus($active)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش را ندارید.'
                ];
            }
            
            // دریافت تنظیمات از دیتابیس - استفاده از جدول bot_settings برای هماهنگی بیشتر
            $existing = DB::table('bot_settings')
                ->where('name', 'bot_active')
                ->first();
                
            if ($existing) {
                // به‌روزرسانی تنظیمات موجود
                DB::table('bot_settings')
                    ->where('name', 'bot_active')
                    ->update(['value' => $active ? '1' : '0']);
            } else {
                // ایجاد تنظیمات جدید
                DB::table('bot_settings')
                    ->insert([
                        'name' => 'bot_active',
                        'value' => $active ? '1' : '0',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
            
            return [
                'success' => true,
                'message' => $active ? 'ربات با موفقیت روشن شد.' : 'ربات با موفقیت خاموش شد.',
                'status' => $active
            ];
        } catch (\Exception $e) {
            error_log("Error in toggleBotStatus: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در تغییر وضعیت ربات: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت آمار ربات
     * @return array
     */
    public function getBotStats()
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            $stats = [];
            
            // تعداد کل کاربران
            $stats['total_users'] = DB::table('users')->count();
            
            // تعداد کل بازی‌های انجام شده
            $stats['total_games'] = DB::table('matches')->count();
            
            // تعداد بازی‌های در جریان
            try {
                $stats['active_games'] = DB::table('matches')
                    ->where('status', 'active')
                    ->count();
            } catch (\Exception $e) {
                $stats['active_games'] = 0;
                echo "خطا در شمارش بازی‌های فعال: " . $e->getMessage() . "\n";
            }
            
            // اطلاعات امروز
            $today = date('Y-m-d');
            
            // تعداد بازی‌های انجام شده امروز
            try {
                $stats['games_today'] = DB::table('matches')
                    ->where('created_at', '>=', $today . ' 00:00:00')
                    ->count();
            } catch (\Exception $e) {
                $stats['games_today'] = 0;
                echo "خطا در شمارش بازی‌های امروز: " . $e->getMessage() . "\n";
            }
            
            // تعداد بازیکنان جدید امروز
            try {
                $stats['new_users_today'] = DB::table('users')
                    ->where('created_at', '>=', $today . ' 00:00:00')
                    ->count();
            } catch (\Exception $e) {
                $stats['new_users_today'] = 0;
                echo "خطا در شمارش کاربران جدید امروز: " . $e->getMessage() . "\n";
            }
            
            // میانگین دلتاکوین‌های بازیکنان (صفرها حساب نشوند)
            try {
                $avg_deltacoins = DB::table('users_extra')
                    ->whereRaw('deltacoins > 0')
                    ->avg('deltacoins');
                $stats['avg_deltacoins'] = $avg_deltacoins ? round($avg_deltacoins, 2) : 0;
            } catch (\Exception $e) {
                $stats['avg_deltacoins'] = 0;
                echo "خطا در محاسبه میانگین دلتاکوین‌ها: " . $e->getMessage() . "\n";
            }
            
            // میانگین جام‌های بازیکنان (صفرها حساب نشوند)
            try {
                $avg_trophies = DB::table('users')
                    ->whereRaw('trophies > 0')
                    ->avg('trophies');
                $stats['avg_trophies'] = $avg_trophies ? round($avg_trophies, 2) : 0;
            } catch (\Exception $e) {
                $stats['avg_trophies'] = 0;
                echo "خطا در محاسبه میانگین جام‌ها: " . $e->getMessage() . "\n";
            }
                        
            // تعداد تراکنش‌های امروز
            try {
                $stats['transactions_today'] = DB::table('transactions')
                    ->where('created_at', '>=', $today . ' 00:00:00')
                    ->count();
            } catch (\Exception $e) {
                $stats['transactions_today'] = 0;
                echo "خطا در شمارش تراکنش‌های امروز: " . $e->getMessage() . "\n";
            }
                
            // تعداد کاربران محدود شده به خاطر اسپم
            try {
                $stats['spam_limited_users'] = DB::table('users')
                    ->where('spam_limited', true)
                    ->count();
            } catch (\Exception $e) {
                // اگر فیلد spam_limited وجود ندارد، مقدار صفر را قرار می‌دهیم
                $stats['spam_limited_users'] = 0;
                echo "فیلد spam_limited در جدول users وجود ندارد\n";
            }
                
            // تعداد پیام‌های رد و بدل شده امروز
            $stats['messages_today'] = DB::table('chat_messages')
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->count();
                
            // میانگین مهره‌های انداخته شده امروز در بازی‌ها
            $stats['avg_moves_today'] = DB::raw("SELECT AVG(total_moves) FROM matches WHERE created_at >= '{$today} 00:00:00'");
                
            // تعداد بازی‌های تمام شده با عدم بازی امروز
            $stats['abandoned_games_today'] = DB::table('matches')
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->where('status', 'abandoned')
                ->count();
                
            // تعداد کل دلتاکوین‌های جمع‌آوری شده امروز
            $stats['deltacoins_earned_today'] = DB::raw("SELECT SUM(amount) FROM coin_transactions WHERE type = 'earn' AND created_at >= '{$today} 00:00:00'");
                
            // تعداد کل دلتاکوین‌های از دست داده شده امروز
            $stats['deltacoins_spent_today'] = DB::raw("SELECT SUM(amount) FROM coin_transactions WHERE type = 'spend' AND created_at >= '{$today} 00:00:00'");
                
            // تعداد کل جام‌های جمع‌آوری شده امروز
            $stats['trophies_earned_today'] = DB::raw("SELECT SUM(amount) FROM trophy_transactions WHERE type = 'earn' AND created_at >= '{$today} 00:00:00'");
                
            // تعداد کل جام‌های از دست داده شده امروز
            $stats['trophies_lost_today'] = DB::raw("SELECT SUM(amount) FROM trophy_transactions WHERE type = 'lose' AND created_at >= '{$today} 00:00:00'");
                
            return [
                'success' => true,
                'message' => 'آمار ربات با موفقیت دریافت شد.',
                'stats' => $stats
            ];
        } catch (\Exception $e) {
            error_log("Error in getBotStats: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در دریافت آمار ربات: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * دریافت آمار ربات برای پیام همگانی
     * @return array آمار ربات
     */
    public function getBotStatistics()
    {
        try {
            // دریافت تعداد کل کاربران
            $total_users = DB::table('users')->count();
            
            // دریافت تعداد کاربران جدید امروز
            $today = date('Y-m-d');
            $new_users_today = DB::table('users')
                ->where('created_at', 'like', $today . '%')
                ->count();
            
            // دریافت تعداد کاربران فعال امروز
            $active_users_today = DB::table('users')
                ->where('last_activity_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->count();
            
            // دریافت تعداد کل بازی‌ها
            $total_games = DB::table('matches')->count();
            
            return [
                'total_users' => $total_users,
                'new_users_today' => $new_users_today,
                'active_users_today' => $active_users_today,
                'total_games' => $total_games
            ];
        } catch (\Exception $e) {
            error_log("Error in getBotStatistics: " . $e->getMessage());
            // در صورت خطا، مقادیر پیش‌فرض برگردانده می‌شوند
            return [
                'total_users' => 0,
                'new_users_today' => 0,
                'active_users_today' => 0,
                'total_games' => 0
            ];
        }
    }
    
    /**
     * افزودن کاربر به عنوان ادمین
     * @param int|string $telegram_id آیدی عددی یا نام کاربری تلگرام
     * @param array $permissions دسترسی‌های ادمین (به صورت آرایه)
     * @return array نتیجه عملیات
     */
    public function addAdmin($telegram_id, $permissions = [])
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // تبدیل نام کاربری به آیدی عددی (اگر نام کاربری وارد شده باشد)
            if (!is_numeric($telegram_id) && strpos($telegram_id, '@') === 0) {
                // حذف @ از ابتدای نام کاربری
                $username = substr($telegram_id, 1);
                
                // جستجوی کاربر با نام کاربری
                $user = DB::table('users')
                    ->where('username', $username)
                    ->first();
                    
                if ($user) {
                    $telegram_id = $user['telegram_id'];
                } else {
                    return [
                        'success' => false,
                        'message' => "کاربری با نام کاربری $telegram_id یافت نشد."
                    ];
                }
            }
            
            // جستجوی کاربر در دیتابیس
            $user = DB::table('users')
                ->where('telegram_id', $telegram_id)
                ->first();
                
            if (!$user) {
                return [
                    'success' => false,
                    'message' => "کاربری با آیدی $telegram_id در دیتابیس یافت نشد."
                ];
            }
            
            // بررسی آیا کاربر قبلاً ادمین است یا خیر
            if (isset($user['is_admin']) && $user['is_admin'] === true) {
                return [
                    'success' => false,
                    'message' => "کاربر {$user['name']} در حال حاضر ادمین است."
                ];
            }
            
            // تنظیم کاربر به عنوان ادمین
            DB::table('users')
                ->where('id', $user['id'])
                ->update([
                    'is_admin' => true,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            // افزودن دسترسی‌های ادمین
            if (!empty($permissions)) {
                // بررسی آیا دسترسی قبلی وجود دارد
                $existing_permissions = DB::table('admin_permissions')
                    ->where('user_id', $user['id'])
                    ->first();
                    
                if ($existing_permissions) {
                    // به‌روزرسانی دسترسی‌ها
                    DB::table('admin_permissions')
                        ->where('user_id', $user['id'])
                        ->update($permissions);
                } else {
                    // ایجاد دسترسی‌ها
                    $permissions_data = array_merge(['user_id' => $user['id']], $permissions);
                    DB::table('admin_permissions')->insert($permissions_data);
                }
            }
            
            return [
                'success' => true,
                'message' => "کاربر {$user['name']} با موفقیت به عنوان ادمین تنظیم شد.",
                'user' => $user
            ];
        } catch (\Exception $e) {
            error_log("Error in addAdmin: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در افزودن ادمین: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * حذف دسترسی ادمین از کاربر
     * @param int|string $telegram_id آیدی عددی یا نام کاربری تلگرام
     * @return array نتیجه عملیات
     */
    public function removeAdmin($telegram_id)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // تبدیل نام کاربری به آیدی عددی (اگر نام کاربری وارد شده باشد)
            if (!is_numeric($telegram_id) && strpos($telegram_id, '@') === 0) {
                // حذف @ از ابتدای نام کاربری
                $username = substr($telegram_id, 1);
                
                // جستجوی کاربر با نام کاربری
                $user = DB::table('users')
                    ->where('username', $username)
                    ->first();
                    
                if ($user) {
                    $telegram_id = $user['telegram_id'];
                } else {
                    return [
                        'success' => false,
                        'message' => "کاربری با نام کاربری $telegram_id یافت نشد."
                    ];
                }
            }
            
            // بررسی آیا ادمین اصلی نیست (نباید ادمین اصلی را حذف کرد)
            $owner_ids = [286420965, 6739124921]; // مالکین اصلی ربات
            if (in_array($telegram_id, $owner_ids)) {
                return [
                    'success' => false,
                    'message' => "حذف دسترسی ادمین اصلی امکان‌پذیر نیست!"
                ];
            }
            
            // جستجوی کاربر در دیتابیس
            $user = DB::table('users')
                ->where('telegram_id', $telegram_id)
                ->first();
                
            if (!$user) {
                return [
                    'success' => false,
                    'message' => "کاربری با آیدی $telegram_id در دیتابیس یافت نشد."
                ];
            }
            
            // بررسی آیا کاربر واقعاً ادمین است یا خیر
            if (!(isset($user['is_admin']) && $user['is_admin'] === true) && 
                !in_array($user['type'], ['admin', 'owner'])) {
                return [
                    'success' => false,
                    'message' => "کاربر {$user['name']} ادمین نیست."
                ];
            }
            
            // حذف دسترسی ادمین
            DB::table('users')
                ->where('id', $user['id'])
                ->update([
                    'is_admin' => false,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            // حذف تمام دسترسی‌های ادمین
            DB::table('admin_permissions')
                ->where('user_id', $user['id'])
                ->delete();
                
            return [
                'success' => true,
                'message' => "دسترسی ادمین از کاربر {$user['name']} با موفقیت حذف شد.",
                'user' => $user
            ];
        } catch (\Exception $e) {
            error_log("Error in removeAdmin: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در حذف دسترسی ادمین: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * فوروارد پیام همگانی
     * @param int $message_id شناسه پیام
     * @param int $chat_id شناسه چت
     * @param bool $include_stats آیا آمار هم ارسال شود
     * @return array
     */
    public function forwardBroadcast($message_id, $chat_id, $include_stats = false)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // دریافت لیست تمام کاربران
            $users = DB::table('users')->get();
            try {
                // اگر فیلد is_blocked وجود داشت، فیلتر کن
                $users = DB::table('users')
                    ->where('is_blocked', false)
                    ->orWhereNull('is_blocked')
                    ->get();
            } catch (\Exception $e) {
                // اگر فیلد is_blocked وجود ندارد، همه کاربران را برمی‌گردانیم
                echo "فیلد is_blocked در جدول users وجود ندارد. همه کاربران انتخاب شدند.\n";
            }
                
            $sent_count = 0;
            $failed_count = 0;
            
            // فوروارد پیام به تمام کاربران
            foreach ($users as $user) {
                try {
                    // چک کردن آیدی تلگرام
                    if (empty($user['telegram_id'])) {
                        $failed_count++;
                        continue;
                    }
                    
                    // استفاده از متد forwardTelegramMessage داخلی کلاس
                    $this->forwardTelegramMessage($user['telegram_id'], $chat_id, $message_id);
                    $sent_count++;
                    
                    // اگر آمار درخواست شده باشد، پس از فوروارد ارسال کنیم
                    if ($include_stats) {
                        $stats = $this->getBotStatistics();
                        
                        $stats_message = "📊 *آمار ربات*\n";
                        $stats_message .= "👥 تعداد کاربران: {$stats['total_users']}\n";
                        $stats_message .= "👤 کاربران فعال 24 ساعت گذشته: {$stats['active_users_today']}\n";
                        $stats_message .= "🎮 تعداد کل بازی‌ها: {$stats['total_games']}\n";
                        $stats_message .= "🆕 کاربران جدید امروز: {$stats['new_users_today']}\n";
                        
                        $this->sendTelegramMessage($user['telegram_id'], $stats_message);
                    }
                    
                    // وقفه کوتاه برای جلوگیری از محدودیت تلگرام
                    usleep(200000); // 0.2 ثانیه
                } catch (\Exception $inner_e) {
                    $failed_count++;
                    error_log("Error forwarding broadcast to user {$user['telegram_id']}: " . $inner_e->getMessage());
                    continue;
                }
            }
            
            echo "فوروارد به {$sent_count} کاربر انجام شد. {$failed_count} مورد ناموفق.\n";
            
            return [
                'success' => true,
                'message' => "پیام همگانی با موفقیت به {$sent_count} کاربر فوروارد شد.",
                'sent_count' => $sent_count,
                'failed_count' => $failed_count
            ];
        } catch (\Exception $e) {
            error_log("Error in forwardBroadcast: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در فوروارد پیام همگانی: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * مدیریت کاربر
     * @param string $user_identifier شناسه کاربر (آیدی تلگرام یا نام کاربری)
     * @return array
     */
    public function getUserInfo($user_identifier)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // جستجوی کاربر براساس شناسه
            $user = null;
            
            if (is_numeric($user_identifier)) {
                // جستجو براساس آیدی تلگرام
                $user = DB::table('users')
                    ->where('telegram_id', $user_identifier)
                    ->first();
            } else {
                // جستجو براساس نام کاربری
                $user = DB::table('users')
                    ->where('username', ltrim($user_identifier, '@'))
                    ->first();
            }
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'کاربر مورد نظر یافت نشد.'
                ];
            }
            
            // اطلاعات تکمیلی
            $extra = null;
            $profile = null;
            $games_count = 0;
            $games_won = 0;
            $friends_count = 0;
            $referrals_count = 0;
            
            // دریافت اطلاعات تکمیلی کاربر (با مدیریت خطا)
            try {
                $extra = DB::table('users_extra')
                    ->where('user_id', $user['id'])
                    ->first();
            } catch (\Exception $e) {
                error_log("Error getting user_extra: " . $e->getMessage());
            }
            
            // دریافت پروفایل کاربر (با مدیریت خطا)
            try {
                $profile = DB::table('user_profiles')
                    ->where('user_id', $user['id'])
                    ->first();
            } catch (\Exception $e) {
                error_log("Error getting user_profiles: " . $e->getMessage());
            }
            
            // دریافت آمار بازی‌های کاربر (با مدیریت خطا)
            try {
                $games_count = DB::table('matches')
                    ->where(function($query) use ($user) {
                        $query->where('player1', $user['id'])
                              ->orWhere('player2', $user['id']);
                    })
                    ->count();
                
                $games_won = DB::table('matches')
                    ->where('winner', $user['id'])
                    ->count();
            } catch (\Exception $e) {
                error_log("Error getting games stats: " . $e->getMessage());
            }
            
            // دریافت تعداد دوستان (با مدیریت خطا)
            try {
                $friends_count = DB::table('friendships')
                    ->where(function($query) use ($user) {
                        $query->where('user_id_1', $user['id'])
                              ->orWhere('user_id_2', $user['id']);
                    })
                    ->count();
            } catch (\Exception $e) {
                error_log("Error getting friends count: " . $e->getMessage());
            }
            
            // دریافت تعداد زیرمجموعه‌ها (با مدیریت خطا)
            try {
                $referrals_count = DB::table('referrals')
                    ->where('referrer_id', $user['id'])
                    ->count();
            } catch (\Exception $e) {
                error_log("Error getting referrals count: " . $e->getMessage());
            }
            
            // ساخت آبجکت نهایی اطلاعات کاربر
            $user_info = [
                'id' => $user['id'],
                'telegram_id' => $user['telegram_id'],
                'username' => $user['username'] ?? 'بدون نام کاربری',
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'trophies' => $user['trophies'] ?? 0,
                'is_admin' => $user['is_admin'] ?? false,
                'is_blocked' => $user['is_blocked'] ?? false,
                'created_at' => $user['created_at'] ?? 'نامشخص',
                'last_activity' => $user['last_activity_at'] ?? $user['updated_at'] ?? 'نامشخص',
                'extra' => $extra ? [
                    'deltacoins' => $extra['deltacoins'] ?? 0,
                    'dozcoins' => $extra['dozcoins'] ?? 0,
                    'played_games' => $extra['played_games'] ?? 0,
                    'wins' => $extra['wins'] ?? 0,
                    'losses' => $extra['losses'] ?? 0,
                    'draws' => $extra['draws'] ?? 0
                ] : [
                    'deltacoins' => 0,
                    'dozcoins' => 0,
                    'played_games' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0
                ],
                'profile' => $profile ? [
                    'full_name' => $profile['full_name'] ?? null,
                    'gender' => $profile['gender'] ?? null,
                    'age' => $profile['age'] ?? null,
                    'bio' => $profile['bio'] ?? null,
                    'province' => $profile['province'] ?? null,
                    'city' => $profile['city'] ?? null,
                    'photo_verified' => $profile['photo_verified'] ?? false,
                    'bio_verified' => $profile['bio_verified'] ?? false
                ] : [
                    'full_name' => null,
                    'gender' => null,
                    'age' => null,
                    'bio' => null,
                    'province' => null,
                    'city' => null,
                    'photo_verified' => false,
                    'bio_verified' => false
                ],
                'stats' => [
                    'games_count' => $games_count,
                    'games_won' => $games_won,
                    'win_rate' => $games_count > 0 ? round(($games_won / $games_count) * 100, 1) : 0,
                    'friends_count' => $friends_count,
                    'referrals_count' => $referrals_count
                ]
            ];
            
            return [
                'success' => true,
                'message' => 'اطلاعات کاربر با موفقیت دریافت شد.',
                'user' => $user_info
            ];
        } catch (\Exception $e) {
            error_log("Error in getUserInfo: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات کاربر: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * تغییر تعداد جام کاربر
     * @param string $user_identifier شناسه کاربر (آیدی تلگرام یا نام کاربری)
     * @param int $amount مقدار تغییر (مثبت یا منفی)
     * @return array
     */
    public function modifyUserTrophies($user_identifier, $amount)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // جستجوی کاربر براساس شناسه
            $user = null;
            
            if (is_numeric($user_identifier)) {
                // جستجو براساس آیدی تلگرام
                $user = DB::table('users')
                    ->where('telegram_id', $user_identifier)
                    ->first();
            } else {
                // جستجو براساس نام کاربری
                $user = DB::table('users')
                    ->where('username', ltrim($user_identifier, '@'))
                    ->first();
            }
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'کاربر مورد نظر یافت نشد.'
                ];
            }
            
            // تغییر تعداد جام‌ها
            $current_trophies = $user['trophies'] ?? 0;
            $new_trophies = max(0, $current_trophies + $amount);
            
            DB::table('users')
                ->where('id', $user['id'])
                ->update([
                    'trophies' => $new_trophies,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            // اطلاع‌رسانی به کاربر
            try {
                $message = "🏆 *تغییر در تعداد جام‌ها*\n\n";
                
                if ($amount > 0) {
                    $message .= "تعداد {$amount} جام به حساب شما اضافه شد.\n";
                } else {
                    $message .= "تعداد " . abs($amount) . " جام از حساب شما کسر شد.\n";
                }
                
                $message .= "تعداد جام‌های فعلی: {$new_trophies}";
                
                // استفاده از متد داخلی برای ارسال پیام
                $this->sendTelegramMessage($user['telegram_id'], $message);
            } catch (\Exception $e) {
                error_log("Error sending trophy update notification: " . $e->getMessage());
            }
            
            return [
                'success' => true,
                'message' => ($amount > 0 ? "افزودن" : "کسر") . " جام با موفقیت انجام شد.",
                'user_id' => $user['telegram_id'],
                'previous_trophies' => $current_trophies,
                'new_trophies' => $new_trophies,
                'change' => $amount
            ];
        } catch (\Exception $e) {
            error_log("Error in modifyUserTrophies: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در تغییر تعداد جام کاربر: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * تغییر تعداد دلتاکوین کاربر
     * @param string $user_identifier شناسه کاربر (آیدی تلگرام یا نام کاربری)
     * @param float $amount مقدار تغییر (مثبت یا منفی)
     * @return array
     */
    public function modifyUserDeltacoins($user_identifier, $amount)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // جستجوی کاربر براساس شناسه
            $user = null;
            
            if (is_numeric($user_identifier)) {
                // جستجو براساس آیدی تلگرام
                $user = DB::table('users')
                    ->where('telegram_id', $user_identifier)
                    ->first();
            } else {
                // جستجو براساس نام کاربری
                $user = DB::table('users')
                    ->where('username', ltrim($user_identifier, '@'))
                    ->first();
            }
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'کاربر مورد نظر یافت نشد.'
                ];
            }
            
            // دریافت یا ایجاد اطلاعات تکمیلی کاربر
            $extra = DB::table('users_extra')
                ->where('user_id', $user['id'])
                ->first();
                
            if (!$extra) {
                DB::table('users_extra')->insert([
                    'user_id' => $user['id'],
                    'deltacoins' => max(0, $amount),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                $current_deltacoins = 0;
                $new_deltacoins = max(0, $amount);
            } else {
                // تغییر تعداد دلتاکوین‌ها
                $current_deltacoins = $extra['deltacoins'] ?? 0;
                $new_deltacoins = max(0, $current_deltacoins + $amount);
                
                DB::table('users_extra')
                    ->where('user_id', $user['id'])
                    ->update([
                        'deltacoins' => $new_deltacoins,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
                
            // اطلاع‌رسانی به کاربر
            try {
                $message = "💰 *تغییر در تعداد دلتاکوین‌ها*\n\n";
                
                if ($amount > 0) {
                    $message .= "تعداد {$amount} دلتاکوین به حساب شما اضافه شد.\n";
                } else {
                    $message .= "تعداد " . abs($amount) . " دلتاکوین از حساب شما کسر شد.\n";
                }
                
                $message .= "تعداد دلتاکوین‌های فعلی: {$new_deltacoins}";
                
                // استفاده از متد داخلی برای ارسال پیام
                $this->sendTelegramMessage($user['telegram_id'], $message);
            } catch (\Exception $e) {
                error_log("Error sending deltacoins update notification: " . $e->getMessage());
            }
            
            return [
                'success' => true,
                'message' => ($amount > 0 ? "افزودن" : "کسر") . " دلتاکوین با موفقیت انجام شد.",
                'user_id' => $user['telegram_id'],
                'previous_deltacoins' => $current_deltacoins,
                'new_deltacoins' => $new_deltacoins,
                'change' => $amount
            ];
        } catch (\Exception $e) {
            error_log("Error in modifyUserDeltacoins: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در تغییر تعداد دلتاکوین کاربر: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * خاموش و روشن کردن ربات
     * @param bool $enabled وضعیت ربات
     * @return array
     */
    public function toggleBot($enabled = true)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // تلاش برای به‌روزرسانی در جدول های مختلف
            $updated = false;
            
            // روش اول: تلاش برای به‌روزرسانی در جدول options
            try {
                $option_exists = DB::table('options')
                    ->where('option_name', 'bot_enabled')
                    ->exists();
                    
                if ($option_exists) {
                    DB::table('options')
                        ->where('option_name', 'bot_enabled')
                        ->update([
                            'option_value' => $enabled ? '1' : '0',
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                } else {
                    DB::table('options')->insert([
                        'option_name' => 'bot_enabled',
                        'option_value' => $enabled ? '1' : '0',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $updated = true;
            } catch (\Exception $e) {
                error_log("Error updating bot status in options table: " . $e->getMessage());
            }
            
            // روش دوم: تلاش برای به‌روزرسانی در جدول bot_settings
            try {
                $bot_setting_exists = DB::table('bot_settings')
                    ->where('name', 'bot_enabled')
                    ->exists();
                    
                if ($bot_setting_exists) {
                    DB::table('bot_settings')
                        ->where('name', 'bot_enabled')
                        ->update([
                            'value' => $enabled ? '1' : '0',
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                } else {
                    DB::table('bot_settings')->insert([
                        'name' => 'bot_enabled',
                        'value' => $enabled ? '1' : '0',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $updated = true;
            } catch (\Exception $e) {
                error_log("Error updating bot status in bot_settings table: " . $e->getMessage());
            }
            
            if (!$updated) {
                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی وضعیت ربات: هیچ جدول قابل استفاده‌ای یافت نشد.'
                ];
            }
            
            return [
                'success' => true,
                'message' => $enabled ? "ربات با موفقیت روشن شد." : "ربات با موفقیت خاموش شد. بازی‌های فعلی تا پایان ادامه می‌یابند."
            ];
        } catch (\Exception $e) {
            error_log("Error in toggleBot: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در خاموش/روشن کردن ربات: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * وضعیت سرور
     * @return array
     */
    public function getServerStatus()
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // اطلاعات مصرف CPU
            $cpu_load = sys_getloadavg();
            
            // اطلاعات مصرف حافظه
            $memory_usage = memory_get_usage(true);
            $memory_peak = memory_get_peak_usage(true);
            
            // اطلاعات فضای دیسک
            $disk_total = disk_total_space('/');
            $disk_free = disk_free_space('/');
            $disk_used = $disk_total - $disk_free;
            
            // دریافت اطلاعات زمان اجرا
            $uptime = shell_exec('uptime -p');
            
            // آمار سیستم
            $status = [
                'cpu' => [
                    'load_1min' => $cpu_load[0],
                    'load_5min' => $cpu_load[1],
                    'load_15min' => $cpu_load[2]
                ],
                'memory' => [
                    'usage' => $this->formatBytes($memory_usage),
                    'peak' => $this->formatBytes($memory_peak)
                ],
                'disk' => [
                    'total' => $this->formatBytes($disk_total),
                    'used' => $this->formatBytes($disk_used),
                    'free' => $this->formatBytes($disk_free),
                    'used_percent' => round($disk_used / $disk_total * 100, 2)
                ],
                'uptime' => trim($uptime),
                'time' => date('Y-m-d H:i:s'),
                'php_version' => PHP_VERSION
            ];
            
            return [
                'success' => true,
                'message' => 'وضعیت سرور با موفقیت دریافت شد.',
                'status' => $status
            ];
        } catch (\Exception $e) {
            error_log("Error in getServerStatus: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در دریافت وضعیت سرور: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * تبدیل بایت به فرمت خوانا
     * @param int $bytes تعداد بایت
     * @return string
     */
    private function formatBytes($bytes)
    {
        if ($bytes <= 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log(1024));
        
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
    
    /**
     * تنظیم مقدار پورسانت زیرمجموعه‌گیری
     * @param array $referral_settings تنظیمات پورسانت
     * @return array
     */
    public function setReferralRewards($referral_settings)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            $successful_updates = 0;
            $failed_updates = 0;
            
            // به‌روزرسانی تنظیمات پورسانت
            foreach ($referral_settings as $key => $value) {
                $updated = false;
                
                // تلاش برای به‌روزرسانی در جدول options
                try {
                    $option_name = "referral_reward_{$key}";
                    $option_exists = DB::table('options')
                        ->where('option_name', $option_name)
                        ->exists();
                        
                    if ($option_exists) {
                        DB::table('options')
                            ->where('option_name', $option_name)
                            ->update([
                                'option_value' => $value,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                    } else {
                        DB::table('options')->insert([
                            'option_name' => $option_name,
                            'option_value' => $value,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    $updated = true;
                } catch (\Exception $e) {
                    error_log("Error updating referral setting {$key} in options table: " . $e->getMessage());
                }
                
                // تلاش برای به‌روزرسانی در جدول bot_settings
                try {
                    $setting_name = "referral_reward_{$key}";
                    $setting_exists = DB::table('bot_settings')
                        ->where('name', $setting_name)
                        ->exists();
                        
                    if ($setting_exists) {
                        DB::table('bot_settings')
                            ->where('name', $setting_name)
                            ->update([
                                'value' => $value,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                    } else {
                        DB::table('bot_settings')->insert([
                            'name' => $setting_name,
                            'value' => $value,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    $updated = true;
                } catch (\Exception $e) {
                    error_log("Error updating referral setting {$key} in bot_settings table: " . $e->getMessage());
                }
                
                if ($updated) {
                    $successful_updates++;
                } else {
                    $failed_updates++;
                }
            }
            
            if ($successful_updates == 0) {
                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی تنظیمات پورسانت: هیچ تنظیمی به‌روزرسانی نشد!'
                ];
            }
            
            return [
                'success' => true,
                'message' => "تنظیمات پورسانت زیرمجموعه‌گیری با موفقیت به‌روزرسانی شد.",
                'successful_updates' => $successful_updates,
                'failed_updates' => $failed_updates,
                'settings' => $referral_settings
            ];
        } catch (\Exception $e) {
            error_log("Error in setReferralRewards: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در تنظیم مقدار پورسانت زیرمجموعه‌گیری: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * تنظیم قیمت دلتاکوین
     * @param float $price قیمت هر دلتاکوین به تومان
     * @return array
     */
    public function setDeltacoinPrice($price)
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            $updated = false;
            
            // روش اول: تلاش برای به‌روزرسانی در جدول options
            try {
                $option_exists = DB::table('options')
                    ->where('option_name', 'deltacoin_price')
                    ->exists();
                    
                if ($option_exists) {
                    DB::table('options')
                        ->where('option_name', 'deltacoin_price')
                        ->update([
                            'option_value' => $price,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                } else {
                    DB::table('options')->insert([
                        'option_name' => 'deltacoin_price',
                        'option_value' => $price,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $updated = true;
            } catch (\Exception $e) {
                error_log("Error updating deltacoin price in options table: " . $e->getMessage());
            }
            
            // روش دوم: تلاش برای به‌روزرسانی در جدول bot_settings
            try {
                $setting_exists = DB::table('bot_settings')
                    ->where('name', 'deltacoin_price')
                    ->exists();
                    
                if ($setting_exists) {
                    DB::table('bot_settings')
                        ->where('name', 'deltacoin_price')
                        ->update([
                            'value' => $price,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                } else {
                    DB::table('bot_settings')->insert([
                        'name' => 'deltacoin_price',
                        'value' => $price,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $updated = true;
            } catch (\Exception $e) {
                error_log("Error updating deltacoin price in bot_settings table: " . $e->getMessage());
            }
            
            if (!$updated) {
                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی قیمت دلتاکوین: هیچ جدول قابل استفاده‌ای یافت نشد.'
                ];
            }
            
            return [
                'success' => true,
                'message' => "قیمت دلتاکوین با موفقیت به {$price} تومان تنظیم شد."
            ];
        } catch (\Exception $e) {
            error_log("Error in setDeltacoinPrice: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در تنظیم قیمت دلتاکوین: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * ارسال پیام تلگرام با استفاده از کلاس داخلی
     * @param int $chat_id شناسه چت کاربر
     * @param string $message متن پیام
     * @param string $parse_mode حالت پارس متن (Markdown, HTML)
     * @param array $reply_markup دکمه‌های پاسخ
     * @return array|bool
     */
    private function sendTelegramMessageV2($chat_id, $message, $parse_mode = 'Markdown', $reply_markup = null)
    {
        try {
            // استفاده از کلاس های داخلی
            require_once __DIR__ . '/TelegramClass.php';
            $telegram = new TelegramClass($_ENV['TELEGRAM_TOKEN']);
            
            return $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => $parse_mode,
                'reply_markup' => $reply_markup
            ]);
        } catch (\Exception $e) {
            error_log("Error in sendTelegramMessageV2: " . $e->getMessage());
            
            // تلاش با روش جایگزین
            try {
                if (function_exists('sendMessage')) {
                    return sendMessage($_ENV['TELEGRAM_TOKEN'], $chat_id, $message, $parse_mode, $reply_markup);
                }
            } catch (\Exception $e2) {
                error_log("Error in fallback sendMessage: " . $e2->getMessage());
            }
            
            return false;
        }
    }
    
    /**
     * دریافت لیست ادمین‌ها
     * @return array
     */
    public function getAdminsList()
    {
        try {
            if (!$this->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'شما دسترسی به این بخش ندارید.'
                ];
            }
            
            // مالکین اصلی ربات
            $owner_ids = [286420965, 6739124921];
            $admins = [];
            
            // دریافت کاربران ادمین با فیلد is_admin
            try {
                $admin_users = DB::table('users')
                    ->where('is_admin', true)
                    ->get();
                
                foreach ($admin_users as $admin) {
                    // بررسی آیا کاربر در لیست مالکین قرار دارد
                    $is_owner = in_array($admin['telegram_id'], $owner_ids);
                    
                    // دریافت دسترسی‌های ادمین
                    $permissions = [];
                    try {
                        $admin_permissions = DB::table('admin_permissions')
                            ->where('user_id', $admin['id'])
                            ->first();
                            
                        if ($admin_permissions) {
                            $permissions = $admin_permissions;
                        }
                    } catch (\Exception $e) {
                        // در صورت نبود جدول یا خطا
                        echo "خطا در دریافت دسترسی‌های ادمین: " . $e->getMessage() . "\n";
                    }
                    
                    $admins[] = [
                        'id' => $admin['id'],
                        'telegram_id' => $admin['telegram_id'],
                        'username' => $admin['username'] ?? '',
                        'name' => $admin['name'] ?? '',
                        'is_owner' => $is_owner,
                        'permissions' => $permissions
                    ];
                }
            } catch (\Exception $e) {
                // ممکن است فیلد is_admin وجود نداشته باشد
                echo "خطا در جستجوی کاربران با فیلد is_admin: " . $e->getMessage() . "\n";
            }
            
            // دریافت کاربران ادمین با فیلد type
            try {
                $admin_type_users = DB::table('users')
                    ->whereIn('type', ['admin', 'owner'])
                    ->get();
                
                foreach ($admin_type_users as $admin) {
                    // بررسی آیا کاربر قبلاً اضافه شده است
                    $exists = false;
                    foreach ($admins as $existing_admin) {
                        if ($existing_admin['telegram_id'] === $admin['telegram_id']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // بررسی آیا کاربر در لیست مالکین قرار دارد
                        $is_owner = in_array($admin['telegram_id'], $owner_ids);
                        
                        // دریافت دسترسی‌های ادمین
                        $permissions = [];
                        try {
                            $admin_permissions = DB::table('admin_permissions')
                                ->where('user_id', $admin['id'])
                                ->first();
                                
                            if ($admin_permissions) {
                                $permissions = $admin_permissions;
                            }
                        } catch (\Exception $e) {
                            // در صورت نبود جدول یا خطا
                            echo "خطا در دریافت دسترسی‌های ادمین: " . $e->getMessage() . "\n";
                        }
                        
                        $admins[] = [
                            'id' => $admin['id'],
                            'telegram_id' => $admin['telegram_id'],
                            'username' => $admin['username'] ?? '',
                            'name' => $admin['name'] ?? '',
                            'is_owner' => $is_owner,
                            'permissions' => $permissions
                        ];
                    }
                }
            } catch (\Exception $e) {
                // ممکن است فیلد type وجود نداشته باشد
                echo "خطا در جستجوی کاربران با فیلد type: " . $e->getMessage() . "\n";
            }
            
            // اضافه کردن مالکین که احتمالاً در دیتابیس نباشند
            foreach ($owner_ids as $owner_id) {
                $exists = false;
                foreach ($admins as $admin) {
                    if (intval($admin['telegram_id']) === $owner_id) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    // جستجوی کاربر در دیتابیس
                    $owner = DB::table('users')
                        ->where('telegram_id', $owner_id)
                        ->first();
                        
                    if ($owner) {
                        $admins[] = [
                            'id' => $owner['id'],
                            'telegram_id' => $owner['telegram_id'],
                            'username' => $owner['username'] ?? '',
                            'name' => $owner['name'] ?? '',
                            'is_owner' => true,
                            'permissions' => []
                        ];
                    } else {
                        // اگر کاربر در دیتابیس نباشد، یک ورودی خالی اضافه می‌کنیم
                        $admins[] = [
                            'id' => null,
                            'telegram_id' => $owner_id,
                            'username' => '',
                            'name' => 'مالک اصلی',
                            'is_owner' => true,
                            'permissions' => []
                        ];
                    }
                }
            }
            
            return [
                'success' => true,
                'message' => 'لیست ادمین‌ها با موفقیت دریافت شد.',
                'admins' => $admins,
                'count' => count($admins)
            ];
        } catch (\Exception $e) {
            error_log("Error in getAdminsList: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در دریافت لیست ادمین‌ها: ' . $e->getMessage()
            ];
        }
    }
}