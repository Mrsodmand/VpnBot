<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Lib\Jdf;
use App\Lib\PasarGuard;
use App\Models\Carts;
use App\Models\Countries;
use App\Models\ExtraBandwidth;
use App\Models\Inbounds;
use App\Models\Message;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Payment;
use App\Models\Plans;
use App\Models\PreOrder;
use App\Models\Seller;
use App\Models\SellerInbound;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TelegramData;
use App\Models\User;
use App\Services\Telegram;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TelegramBotController extends Controller
{
    protected $chatId;
    protected $panel;
    protected $messageId;
    protected $method;

    protected $token;
    protected $telData;
    protected $text;
    protected $type;
    protected $user;
    protected $callbackData;
    protected $callbackId;
    protected $telegramSdk;
    protected $isSeller;
    protected $isAdmin;
    protected $from;
    protected $isJoined = false;
    protected $isPhoto = false;
    protected $fileId = false;
    protected $file_unique_id;
    protected $customData = [];


    public function __construct(Request $request)
    {
        $data = $request->all();

        $telData = new TelegramData();
        $telData->data = json_encode($data);
        $telData->path = 'before';
        $telData->save();

        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->telegramSdk = new Telegram($this->token);

        if (array_key_exists('message', $data)) {

            $this->messageId = $data['message']['message_id'];
            $this->chatId = $data['message']['chat']['id'];
            $this->type = 'text';
            if (array_key_exists('photo', $data['message'])) {
                $this->text = "photo";
                $this->fileId = $data['message']['photo'][2]['file_id'];
                $this->file_unique_id = $data['message']['photo'][2]['file_unique_id'];
            } elseif (array_key_exists('text', $data['message'])) {
                $this->text = PersianNumToEn($data['message']['text']);
            }

        } elseif (array_key_exists('callback_query', $data)) {

            $this->chatId = $data['callback_query']['message']['chat']['id'];
            $this->messageId = $data['callback_query']['message']['message_id'];
            $this->callbackData = $data['callback_query']['data'];
            $this->callbackId = $data['callback_query']['id'];
            $this->type = 'callback_query';
            $this->from = $data['callback_query']['from'];

            if (array_key_exists('photo', $data['callback_query']['message'])) {
                $this->text = $data['callback_query']['message']['caption'];
                $this->isPhoto = true;
            } elseif (array_key_exists('text', $data['callback_query']['message'])) {
                $this->text = PersianNumToEn($data['callback_query']['message']['text']);
            }
        } elseif (array_key_exists('my_chat_member', $data)) {
            $this->chatId = $data['my_chat_member']['chat']['id'];
            $this->customData = [
                'oldStatus' => $data['my_chat_member']['old_chat_member']['status'],
                'newStatus' => $data['my_chat_member']['new_chat_member']['status'],
                $this->type = 'my_chat_member'
            ];
        }

        $this->telData = $data;

        $this->checkUser();
        $user = $this->user;
        $this->isSeller = $user->is_seller;
        $this->isAdmin = $user->is_admin;
    }

    public function index()
    {
        $user = $this->user;
        if ($user->path == 'disabled') {
            return $this->botIsNotActive();
        }

        if ($user->status != 1) {
            return $this->accountIsDisabled();
        }

//        $setting = Setting::where('key', 'channel-join')->first();
//        if (!is_null($setting) && $setting->value == 1) {
//            $this->ifUserIsJoined();
//            if ($this->isJoined) {
//                return $this->joinFirst();
//            }
//        }

        if ($this->chatId > 0) {
            switch ($this->type) {
                case "callback_query":
                    $this->method = 'edit';
                    return $this->callbackQueryAction();
                    break;
                case "text":
                    $this->method = 'send';
                    return $this->NormalTextAction();
                    break;
                case "my_chat_member":
                    return $this->checkChatMember();
                    break;
            }
        }
    }

    protected function callbackQueryAction()
    {
        try {
            $data = explode('|', $this->callbackData);

            if ($this->callbackData == 'ignore') {
                return $this->ignore();
            }
            foreach ($data as $key => $item) {
                list($name, $id) = explode('=', $item);
                $type[$name] = $id;
            }

            if (array_key_exists('action', $type)) {
                switch ($type['action']) {
                    case 'delete':
                        $this->deleteChat();
                        $this->method = 'toUser';
                        break;
                }
            }

            $this->updatePath($type['type']);

            $telData = new TelegramData();
            $telData->data = json_encode($this->telData);
            $telData->tel_id = $this->chatId;
            $telData->path = $type['type'];
            $telData->types = json_encode($type);
            $telData->save();

            switch ($type['type']) {
                // all access
                case 'home':
                    return $this->home($type);
                    break;
                case 'clientService':
                    return $this->clientService($type);
                    break;
                case 'clientSelectCountry':
                    return $this->clientSelectCountry($type);
                    break;
                case 'clientSelectPlan':
                    return $this->clientSelectPlan($type);
                    break;
                case 'clientSelectExtra':
                    return $this->clientSelectExtra($type);
                    break;
                case 'clientSelectCount':
                    return $this->clientSelectCount($type);
                    break;
                case 'clientSelectName':
                    return $this->clientSelectName($type);
                    break;
                case 'clientFinalStep':
                    return $this->clientFinalStep($type);
                    break;
                case 'paymentCartBeCart':
                    return $this->paymentCartBeCart($type);
                    break;
                case 'paymentSendReceipt':
                    return $this->paymentSendReceipt($type);
                    break;
                case 'paymentWallet':
                    return $this->paymentWallet($type);
                    break;

                // Profile
                case "profile":
                    $this->profile($type);
                    break;
                case "addFund":
                    $this->addFund($type);
                    break;
                case "addFundStepOne":
                    $this->addFundStepOne($type);
                    break;
                case "addFundStepTwo":
                    $this->addFundStepTwo($type);
                    break;
                case "addFundCustomAmount":
                    $this->addFundCustomAmount($type);
                    break;

                // Order
                case "clientOrders":
                    return $this->clientOrders($type);
                    break;
                case "clientSingleOrder":
                    $this->deleteChat();
                    return $this->clientSingleOrder($type);
                    break;
                case "clientChangeConfigName":
                    $this->method = 'toUser';
                    return $this->clientChangeConfigName($type);
                    break;
                case "clientChangeConfigUid":
                    return $this->clientChangeConfigUid($type);
                    break;
                case "clientRenewOrder":
                    return $this->clientRenewOrder($type);
                    break;
                case "clientSubmitRenew":
                    return $this->clientSubmitRenew($type);
                    break;

                case "clientBuyExtra":
                    return $this->clientBuyExtra($type);
                    break;
                case "clientSubmitExtra":
                    return $this->clientSubmitExtra($type);
                    break;

                // Seller Access

                // Admin Panel Admin Access

                case "admin-home":
                    return $this->adminMenu($type);
                    break;

                case "adminPanelMenu":
                    return $this->adminPanelMenu($type);
                    break;
                case "admin-panels":
                    return $this->adminPanels($type);
                    break;
                case "adminCreatePanel":
                    return $this->adminCreatePanel($type);
                    break;
                case "adminPanelDetail":
                    return $this->adminPanelDetail($type);
                    break;
                case "adminEditPanel":
                    return $this->adminEditPanel($type);
                    break;
                case "adminUpdatePanel":
                    return $this->adminUpdatePanel($type);
                    break;
                case "adminConnectPanel":
                    return $this->adminConnectPanel($type);
                    break;
                case "adminGetInbounds":
                    return $this->adminGetInbounds($type);
                    break;
                case "adminPanelDeleteDetail":
                    return $this->adminPanelDeleteDetail($type);
                    break;
                case "adminPanelDeleteSubmit":
                    return $this->adminPanelDeleteSubmit($type);
                    break;

                case "adminUserList":
                    return $this->adminUserList($type);
                    break;
                case "adminUserSearch":
                    return $this->adminUserSearch($type);
                    break;
                case "adminUserDetail":
                    return $this->adminUserDetail($type);
                    break;
                case "adminUserBalance":
                    return $this->adminUserBalance($type);
                    break;
                case "adminUserBalanceAction":
                    return $this->adminUserBalanceAction($type);
                    break;
                case "adminUserSettings":
                    return $this->adminUserSettings($type);
                    break;
                case "adminUserIsAdmin":
                    return $this->adminUserIsAdmin($type);
                    break;
                case "adminUserIsSeller":
                    return $this->adminUserIsSeller($type);
                    break;
                case "adminUserSellerDiscount":
                    return $this->adminUserSellerDiscount($type);
                    break;
                case "adminUserSellerInbounds":
                    return $this->adminUserSellerInbounds($type);
                    break;
                case "adminUserSellerChangeInbound":
                    return $this->adminUserSellerChangeInbound($type);
                    break;
                case "adminToggleUserStatus":
                    return $this->adminToggleUserStatus($type);
                    break;

                case "adminPlans":
                    return $this->adminPlans($type);
                    break;
                case "adminPlanCreate":
                    return $this->adminPlanCreate($type);
                    break;
                case "adminPlanDetail":
                    return $this->adminPlanDetail($type);
                    break;
                case "adminEditPlan":
                    return $this->adminEditPlan($type);
                    break;
                case "adminUpdatePlan":
                    return $this->adminUpdatePlan($type);
                    break;
                case "adminPlanDeleteDetail":
                    return $this->adminPlanDeleteDetail($type);
                    break;
                case "adminPlanDeleteSubmit":
                    return $this->adminPlanDeleteSubmit($type);
                    break;

                case "adminSetting":
                    return $this->adminSetting($type);
                    break;
                case "adminSettingSell":
                    return $this->adminSettingSell($type);
                    break;
                case "adminToggleSetting":
                    return $this->adminToggleSetting($type);
                    break;
                case "adminSettingCommission":
                    return $this->adminSettingCommission($type);
                    break;
                case "adminSettingCommissionText":
                    return $this->adminSettingCommissionText($type);
                    break;
                case "adminPaymentSetting":
                    return $this->adminPaymentSetting($type);
                    break;
                case "adminCartBeCartStatus":
                    return $this->adminCartBeCartStatus($type);
                    break;
                case "adminCartBeCartRandom":
                    return $this->adminCartBeCartRandom($type);
                    break;
                case "adminCartSetting":
                    return $this->adminCartSetting($type);
                    break;

                case "adminBotSetting":
                    return $this->adminBotSetting($type);
                    break;
                case "adminChangeSetting":
                    return $this->adminChangeSetting($type);
                    break;
                case "adminChangeSettingSubmit":
                    return $this->adminChangeSettingSubmit($type);
                    break;
                case "adminSettingChangeValue":
                    return $this->adminSettingChangeValue($type);
                    break;

                case "adminCountries":
                    return $this->adminCountries($type);
                    break;
                case "adminCountriesCreate":
                    return $this->adminCountriesCreate($type);
                    break;
                case "adminCountriesDetail":
                    return $this->adminCountriesDetail($type);
                    break;
                case "adminCountriesEdit":
                    return $this->adminCountriesEdit($type);
                    break;
                case "adminCountriesUpdate":
                    return $this->adminCountriesUpdate($type);
                    break;

                case "adminService":
                    return $this->adminService($type);
                    break;
                case "adminServiceCreate":
                    return $this->adminServiceCreate($type);
                    break;
                case "adminServiceDetail":
                    return $this->adminServiceDetail($type);
                    break;
                case "adminServiceEdit":
                    return $this->adminServiceEdit($type);
                    break;
                case "adminServiceUpdate":
                    return $this->adminServiceUpdate($type);
                    break;
                case "adminServiceDeleteDetail":
                    return $this->adminServiceDeleteDetail($type);
                    break;
                case "adminServiceDeleteSubmit":
                    return $this->adminServiceDeleteSubmit($type);
                    break;

                case "adminExtraBandwidths":
                    return $this->adminExtraBandwidths($type);
                    break;
                case "adminExtraBandwidthsCreate":
                    return $this->adminExtraBandwidthsCreate($type);
                    break;
                case "adminExtraBandwidthsDetail":
                    return $this->adminExtraBandwidthsDetail($type);
                    break;
                case "adminExtraBandwidthsEdit":
                    return $this->adminExtraBandwidthsEdit($type);
                    break;
                case "adminExtraBandwidthsUpdate":
                    return $this->adminExtraBandwidthsUpdate($type);
                    break;
                case "adminExtraBandwidthsDelete":
                    return $this->adminExtraBandwidthsDelete($type);
                    break;
                case "adminExtraBandwidthsDeleteSubmit":
                    return $this->adminExtraBandwidthsDeleteSubmit($type);
                    break;

                case "adminCartList":
                    return $this->adminCartList($type);
                    break;
                case "adminCartCreate":
                    return $this->adminCartCreate($type);
                    break;
                case "adminCartDetail":
                    return $this->adminCartDetail($type);
                    break;
                case "adminCartEdit":
                    return $this->adminCartEdit($type);
                    break;
                case "adminCartUpdate":
                    return $this->adminCartUpdate($type);
                    break;
                case "adminCartBeCartText":
                    return $this->adminCartBeCartText($type);
                    break;

                case "adminRejectCartReceipt":
                    return $this->adminRejectCartReceipt($type);
                    break;
                case "adminConfirmCartReceipt":
                    return $this->adminConfirmCartReceipt($type);
                    break;

                case "adminInbounds":
                    return $this->adminInbounds($type);
                    break;
                case "adminInboundList":
                    return $this->adminInboundList($type);
                    break;
                case "adminPasarGuardGroups":
                    return $this->adminPasarGuardGroups($type);
                    break;
                case "AdminPGGDA":
                    return $this->adminPasarGuardGroupDetail($type);
                    break;
                case "adminPasarGuardGroupsEdit":
                    return $this->adminPasarGuardGroupsEdit($type);
                    break;
                case "adminPasarGuardGroupsUpdate":
                    return $this->adminPasarGuardGroupsUpdate($type);
                    break;
                case "adminToggleInboundStatus":
                    return $this->adminToggleInboundStatus($type);
                    break;

                case "adminChargeAmount":
                    return $this->adminChargeAmount($type);
                    break;
                case "adminChargeAmountAdd":
                    return $this->adminChargeAmountAdd($type);
                    break;
                case "adminChargeAmountDelete":
                    return $this->adminChargeAmountDelete($type);
                    break;

                case "adminOrdersList":
                    return $this->adminOrdersList($type);
                    break;
                case "adminOrderSearch":
                    return $this->adminOrderSearch($type);
                    break;
                case "adminOrderSingle":
                    return $this->adminOrderSingle($type);
                    break;
                case "adminOrderChangeBw":
                    return $this->adminOrderChangeBw($type);
                    break;
                case "adminOrderChangeTime":
                    return $this->adminOrderChangeTime($type);
                    break;
            }

        } catch (\Exception $exception) {
            $this->telData['errors'] = $exception->getMessage() . '-LINE:' . $exception->getLine();
            $telData = new TelegramData();
            $telData->data = json_encode($this->telData);
            $telData->tel_id = $this->chatId;
            $telData->path = 'error';
            $telData->types = isset($type) ? json_encode($type) : '';
            $telData->save();
        }
    }

    protected function NormalTextAction()
    {

        $telData = new TelegramData();
        $telData->data = json_encode($this->telData);
        $telData->tel_id = $this->chatId;
        $telData->path = $this->text;
        $telData->save();
        $this->method = 'toUser';
        switch ($this->text) {
            case '/start':
                if ($this->isAdmin) {
                    return $this->adminMenu(['id' => null]);
                }
                return $this->home();
                break;
            case '/connectAccount':
                return $this->connectAccount();
                break;
            case '/services':
                return $this->services();
                break;
            case "/profile":
                $this->profile();
                break;
            default:
                $this->checkPath();
                break;
        }
    }

    protected function checkPath()
    {
        $user = $this->user;

        switch ($user->path) {
            case 'adminUpdatePanel':
                return $this->adminUpdatePanel();
                break;
            case 'adminUpdatePlan':
                return $this->adminUpdatePlan();
                break;
            case 'adminUserList':
                $type['search'] = $this->text;
                return $this->adminUserList($type);
                break;
            case 'adminOrdersList':
                $type['search'] = $this->text;
                return $this->adminOrdersList($type);
                break;
            case 'adminUserBalanceActionBalance':
                $type['search'] = $this->text;
                return $this->adminUserBalanceActionBalance($type);
                break;
            case 'adminUserSellerDiscountAmount':
                $type['search'] = $this->text;
                return $this->adminUserSellerDiscountAmount($type);
                break;
            case 'adminSettingCommissionTextEdit':
                return $this->adminSettingCommissionTextEdit();
                break;
            case 'adminSettingCommission':
                return $this->adminSettingCommissionEdit();
                break;
            case 'adminCountriesUpdate':
                return $this->adminCountriesUpdate();
                break;
            case 'adminServiceUpdate':
                return $this->adminServiceUpdate();
                break;
            case 'adminExtraBandwidthsUpdate':
                return $this->adminExtraBandwidthsUpdate();
                break;
            case 'adminCartUpdate':
                return $this->adminCartUpdate();
                break;
            case 'adminCartBeCartTextUpdate':
                return $this->adminCartBeCartTextUpdate();
                break;
            case 'adminChangeSettingSubmit':
                $type['value'] = $this->text;
                return $this->adminChangeSettingSubmit($type);
                break;
            case 'clientFinalStep':
                $type['name'] = $this->text;
                return $this->clientFinalStep($type);
                break;
            case 'sendCartBeCartReceipt':
                return $this->paymentSubmitCartBeCartReceipt();
                break;
            case 'clientOrders':
                $type['search'] = $this->text;
                return $this->clientOrders($type);
                break;
            case 'clientChangeConfigNameSubmit':
                $type['name'] = $this->text;
                return $this->clientChangeConfigNameSubmit($type);
                break;
            case 'adminPasarGuardGroupUpdate':
                $type['name'] = $this->text;
                return $this->adminPasarGuardGroupUpdate($type);
                break;
            case 'adminChargeAmountSubmit':
                $type['text'] = $this->text;
                return $this->adminChargeAmountSubmit($type);
                break;
            case 'adminOrderChangeBwSubmit':
                $type['text'] = $this->text;
                return $this->adminOrderChangeBwSubmit($type);
                break;
            case 'adminOrderChangeTimeSubmit':

                $type['text'] = $this->text;
                return $this->adminOrderChangeTimeSubmit($type);
                break;
            case 'addFundCustomAmountSubmit':
                $type['amount'] = $this->text;
                return $this->addFundCustomAmountSubmit($type);
                break;
            case 'disabled':
                return $this->botIsNotActive();
                break;
        }
    }

    protected function botIsNotActive()
    {
        $text = headTitle('اطلاعیه');
        $text .= "👋 کاربر گرامی

در حال حاضر امکان ثبت نام کاربر جدید وجود ندارد.

⏳ به محض فعال شدن مجدد ثبت نام، از طریق ربات به شما اطلاع رسانی خواهد شد.

از صبر و شکیبایی شما سپاسگزاریم 🙏";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function accountIsDisabled()
    {
        $text = headTitle('اطلاعیه');
        $text .= "👋 کاربر گرامی
حساب کاربری شما غیرفعال شده است.";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function checkChatMember()
    {
        $customData = $this->customData;

        if (
            $customData['oldStatus'] == 'member' &&
            $customData['newStatus'] == 'kicked'
        ) {
            User::where('tel_id', $this->chatId)
                ->update([
                    'status' => -2
                ]);
        }
        if (
            $customData['oldStatus'] == 'kicked' &&
            $customData['newStatus'] == 'member'
        ) {
            User::where('tel_id', $this->chatId)
                ->update([
                    'status' => 1
                ]);
        }
    }


    // Functions
    private function checkUser()
    {
        $user = User::where('tel_id', $this->chatId)->first();
        if (is_null($user)) {
            $setting = Setting::where('key', 'join-bot')->first();

            $path = 'disabled';
            $status = -3;
            if ($setting->value == 1) {
                $path = 'start';
                $status = -3;
            }
            $firstName = array_key_exists('first_name', $this->telData['message']['from']) ? $this->telData['message']['from']['first_name'] : null;
            $lastName = array_key_exists('last_name', $this->telData['message']['from']) ? $this->telData['message']['from']['last_name'] : null;
            $username = array_key_exists('username', $this->telData['message']['from']) ? $this->telData['message']['from']['username'] : null;

            $user = new User();
            $user->first_name = $firstName;
            $user->last_Name = $lastName;
            $user->username = $username;
            $user->tel_id = $this->chatId;
            $user->path = $path;
            $user->balance = 0;
            $user->status = $status;
            $user->save();
        }
        $this->user = $user;
    }

    private function ifUserIsJoined()
    {
        $channel_id = Setting::where('key', 'channel_id')->first();
        if (!is_null($channel_id)) {
            if ($channel_id->value != 0) {
                $data = [
                    'chat_id' => $channel_id->value,
                    'user_id' => $this->chatId,
                ];

                $result = $this->telegramSdk->getChatMember($data);
                if (array_key_exists('ok', $result) && $result['ok'] == true) {
                    return $this->isJoined = true;
                }
            }
        }
        return $this->isJoined = false;
    }

    private function checkUserIsJoined()
    {
        $this->ifUserIsJoined();
        if ($this->isJoined) {
            return $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => "لطفا ابتدا وارد کانال شوید.",
                'show_alert' => true,
                'cache_time' => 1,
            ]);

        }
        $this->updatePath('start');
        return  $this->home();
    }

    protected function joinFirst()
    {
        $channel_id = Setting::where('key', 'channel_id')->first();
        $channel_id = str_replace(['https://t.me/','http://t.me/','@'],'',$channel_id->value);

        $text = headTitle('اطلاعیه');
        $text .= "برای استفاده از ربات لطفا وارد کانال شوید";
        $buttons[][] = ['text' => "کانال", 'url' => "https://t.me/$channel_id"];
        $buttons[][] = ['text' => "عضو شدم", 'callback_data' => "type=checkUserIsJoined"];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
            'parse_mode' => 'HTML',
        ];
        $this->method = 'toUser';
        return $this->sendMessage($data, 'message');

    }

    private function createInlineKeyboard(array $rows): array
    {
        $keyboard = ['inline_keyboard' => []];

        foreach ($rows as $rowButtons) {

            $row = [];

            foreach ($rowButtons as $button) {

                $btn = [
                    'text' => $button['key']
                ];

                switch ($button['type']) {

                    case 'callback_data':
                        $btn['callback_data'] = $button['data'];
                        break;

                    case 'url':
                        $btn['url'] = $button['data'];
                        break;

                    case 'web_app':
                        $btn['web_app'] = ['url' => $button['data']];
                        break;
                }

                $row[] = $btn;
            }

            $keyboard['inline_keyboard'][] = $row;
        }

        return $keyboard;
    }

    private function sendMessage($data, $messageMethod)
    {
        if (is_null($messageMethod)) {
            $messageMethod = 'message';
        }

        if ($messageMethod == 'message') {
            if ($this->method == 'edit') {
                $data['message_id'] = $this->messageId;
                return $this->telegramSdk->editMessage($data);
            } elseif ($this->method == 'temp') {
                return $this->telegramSdk->sendMessage($data);
            } elseif ($this->method == 'toUser') {
                return $this->telegramSdk->sendMessage($data);
            } else {
                $this->deleteChat();
                return $this->telegramSdk->sendMessage($data);
            }
        } elseif ($messageMethod == 'photo') {
            $this->deleteChat();
            $this->telegramSdk->sendPhoto($data);
        }
    }

    private function sendTemporaryMessage($text)
    {
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        $this->method = 'temp';
        $result = $this->sendMessage($data, 'message');

        if (!empty($result['ok'])) {
            sleep(5);
            $this->messageId = $result['result']['message_id'];
            $this->deleteChat();
        }

        return;
    }

    private function deleteChat()
    {
        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId
        ];
        $this->telegramSdk->deleteMessage($data);
    }

    private function countryToFlag($flag)
    {
        $countryCode = strtoupper($flag);
        return mb_chr(ord($countryCode[0]) + 127397)
            . mb_chr(ord($countryCode[1]) + 127397);
    }

    private function updatePath($path)
    {
        $user = $this->user;

        $user->path = $path;
        $user->save();
    }

    private function adminFooterButtons($path = null)
    {
        if ($path == null) {

            return [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'type=admin-home',
                    'style' => 'danger'
                ],
            ];

        } else {

            return [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home',
                    'style' => 'primary'
                ],
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => $path,
                    'style' => 'danger'
                ],
            ];
        }
    }

    private function ignore()
    {
        return $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "دکمه اشتباهی رو کلیک کردی!",
            'show_alert' => true,
            'cache_time' => 1,
        ]);
    }

    private function clientFooterButtons($path = null)
    {
        if ($path == null) {

            return [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'type=home',
//                    'style' => 'danger'
                ],
            ];

        } else {

            return [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=home',
//                    'style' => 'primary'
                ],
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => $path,
//                    'style' => 'danger'
                ],
            ];
        }
    }

    private function paginationFooterButton($list, $page, $type)
    {
        $pagination = [];

        if ($list->lastPage() > 1) {

            $nextCallback = $prevCallback = 'ignore';
            $nextCallbackStyle = $prevCallbackStyle = 'danger';

            if ($list->currentPage() > 1) {
                $prevCallback = "type=$type|page=" . ($page - 1);
                $prevCallbackStyle = '';

            }

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => $prevCallback,
                'style' => $prevCallbackStyle
            ];

            $pagination[] = [
                'text' => "📄 {$list->currentPage()} / {$list->lastPage()}",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ];

            if ($list->hasMorePages()) {
                $nextCallback = "type=$type|page=" . ($page + 1);
                $nextCallbackStyle = '';
            }

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => $nextCallback,
                'style' => $nextCallbackStyle
            ];

            return $pagination;
        }
    }

    protected function home($type = null)
    {
        $user = $this->user;
        $homePage = Setting::where('key', 'home-page')->first();
        $supportId = Setting::where('key', 'support_id')->first();

        $buttons[][] = ['text' => "📦 خرید سرویس", 'callback_data' => 'type=clientService', 'style' => 'success'];
        $buttons[] = [
            ['text' => "📑 سفارشات من", 'callback_data' => 'type=clientOrders'],
            ['text' => "💰 شارژ کیف پول", 'callback_data' => 'type=addFund'],
        ];

        if (!is_null($supportId) && !is_null($supportId->value)) {
            $buttons[] = [
                ['text' => "👤 حساب کاربری", 'callback_data' => 'type=profile'],
                ['text' => "🌐 پشتیبانی", 'url' => "https://t.me/$supportId->value"],
            ];
        } else {
            $buttons[] = [
                ['text' => "👤 حساب کاربری", 'callback_data' => 'type=profile'],
            ];
        }


        if ($user->is_admin) {
            $buttons[] = [
                ['text' => "پنل مدیریت", 'callback_data' => 'type=admin-home'],
            ];
        }

        if (!is_null($homePage) && !is_null($homePage->value)) {
            $text = $homePage->value;
        } else {
            $text = "🚀 به ربات ما خوش آمدید

📱 یک پلتفرم کامل برای خرید و مدیریت کانفیگ‌های VPN با سرعت بالا و دسترسی امن به اینترنت آزاد

✨ خدمات ما:
• 🔐 کانفیگ VPN پرسرعت (V2Ray)
• 🌍 سرورهای متنوع از کشورهای مختلف
• ⚡ اتصال پایدار و بدون قطعی
• 📱 مناسب برای موبایل، ویندوز و مک
• 🔄 تحویل آنی پس از خرید
• 💰 قیمت مناسب و مقرون‌به‌صرفه
• 📊 پنل مدیریت سفارش‌ها
• 🔒 امنیت بالا و حفظ حریم خصوصی

🔐 چرا ربات ما؟
سریع • امن • پایدار • پشتیبانی ۲۴ ساعته • تحویل فوری

👉 برای شروع، یکی از سرویس‌های زیر را انتخاب کنید:";
        }
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFund($data)
    {
        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        $gateway = Setting::where('key', 'gateway')->first();
        $crypto = Setting::where('key', 'crypto')->first();

        if (!is_null($gateway) && $gateway->value == 1) {
            $buttons[][] = ['text' => "📦 درگاه", 'callback_data' => 'type=addFundStepOne|value=Online'];
        }

        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $buttons[][] = ['text' => "📦 کارت به کارت", 'callback_data' => 'type=addFundStepOne|value=Cart'];
        }

        if (!is_null($crypto) && $crypto->value == 1) {
            $buttons[][] = ['text' => "📦 ارز دیجیتال", 'callback_data' => 'type=addFundStepOne|value=Crypto'];
        }

        $buttons[][] = ['text' => "برگشت", 'callback_data' => 'type=home',];

        $balance = number_format($this->user->balance);
        $data = [
            'chat_id' => $this->chatId,
            'text' => "شارژ کیف پول \n موجودی کیف پول: {$balance}",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFundStepOne($data)
    {
        $method = $data['value'];

        $setting = Setting::where('key', 'charge_amount')->first();
        if (!is_null($setting) && !empty($setting->value)) {

            $amounts = explode(',', $setting->value);

            $row = [];

            foreach ($amounts as $key => $amount) {

                $amount = number_format(trim($amount));

                if ($amount === '') {
                    continue;
                }

                $row[] = [
                    'text' => "💰 {$amount} T",
                    'callback_data' => "type=addFundStepTwo|amount={$amount}|key=$method",
                ];

                // دو ستون در هر ردیف
                if (count($row) == 2) {
                    $keyboard['inline_keyboard'][] = $row;
                    $row = [];
                }
            }

            // اگر تعداد فرد بود
            if (!empty($row)) {
                $keyboard['inline_keyboard'][] = $row;
            }
        }
        $keyboard['inline_keyboard'][][] = [
            'text' => "💰 مبلغ دلخواه",
            'callback_data' => "type=addFundCustomAmount|key=$method",
        ];
        $keyboard['inline_keyboard'][] = $this->clientFooterButtons("type=addFund");

        $data = [
            'chat_id' => $this->chatId,
            'text' => "شارژ کیف پول",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFundStepTwo($data)
    {
        $key = $data['key'];
        $amount = $data['amount'];

        switch ($key) {
            case "Online":
                return $this->addFundOnline($data);
                break;
            case "Cart":
                return $this->addFundCart($data);
                break;
            case "Crypto":
                return $this->addFundCrypto($data);
                break;
        }
    }

    protected function addFundCustomAmount($data)
    {
        $key = $data['key'];
        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $tel_detail['add-fund-method'] = $key;
        $user->tel_detail = $tel_detail;
        $user->save();

        $this->updatePath('addFundCustomAmountSubmit');

        $keyboard['inline_keyboard'][] = $this->clientFooterButtons("type=addFund");

        $data = [
            'chat_id' => $this->chatId,
            'text' => "لطفا مبلغ مورد نظر را به تومان وارد کنید.",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFundCustomAmountSubmit($data)
    {
        $validate = Validator::make([
            'amount' => $this->text
        ], [
            'amount' => ['required', 'numeric', 'min:1000'],
        ], [
            'amount.required' => '❌ لطفا مقدار تخفیف را وارد کنید.',
            'amount.numeric' => '❌ مقدار باید عددی باشد.',
            'amount.min' => '❌ حداقل مقدار 1000 است.',
        ]);

        if ($validate->fails()) {
            return $this->sendTemporaryMessage($validate->errors()->first());
        }

        $user = $this->user;
        $data['key'] = $user->tel_detail['add-fund-method'];
        $data['amount'] = $this->text;

        return $this->addFundStepTwo($data);

    }

    private function addFundOnline($data)
    {

    }

    private function addFundCart($data)
    {
        $this->updatePath('sendCartBeCartReceipt');

        $amount = $data['amount'];
        $random = Setting::where('key', 'cart_be_cart_random')->first();
        $support = Setting::where('key', 'support_id')->first();
        $isRandom = false;
        if (!is_null($random) && $random->value == 1) {
            $isRandom = true;
        }

        if ($isRandom) {
            $ids = Carts::where('status', 1)
                ->orderBy('id', 'desc')
                ->limit(100)
                ->pluck('id');

            $card = Carts::find($ids->random());
        } else {
            $card = Carts::orderby('id', 'desc')
                ->where('status', 1)
                ->where('is_default', 1)
                ->first();
        }
        if (is_null($card)) {
            $card = Carts::orderby('id', 'desc')
                ->where('status', 1)
                ->first();
        }
        $user = $this->user;
        $amount = (int)str_replace(',', '', $amount);

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = 0;
        $payment->price = $amount;
        $payment->status = 0;
        $payment->type = 4;
        $payment->expired_at = Carbon::now();
        $payment->save();

        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $tel_detail['payment-id'] = $payment->id;
        $tel_detail['payment-type'] = 'cart-be-cart';
        $tel_detail['payment-cart-number'] = $card->cart;
        $tel_detail['payment-cart-name'] = $card->name;

        $user->tel_detail = $tel_detail;
        $user->save();


        $rialAmount = number_format($amount * 10);
        $amount = number_format($amount);
        $text = "🟩درخواست  شما ثبت شد!

👝 مبلغ سفارش : <code>{$amount}</code> تومان
🔘 جهت تکمیل سفارش مبلغ فاکتور را به تومان به شماره کارت زیر واریز نموده و پس از واریز تصویر فیش را در همین مرحله برای ربات ارسال نمایید :

💳 <code> {$card->cart} </code>
👤 به نام {$card->name}

در هنگام انتقال، از نوشتن توضیحات انتقال حاوی کلمات یا عبارات حساس مانند «وی‌پی‌ان vpn»، «کانفیگ»، «ویتوری»، «آی‌پی ثابت» و مشابه آن خودداری کنید
درصورت درج هر یک از این کلمات، حساب شما مسدود شده و کیف‌پول شارژ نخواهد شد

⚠️لطفا برای هربار انتقال به شماره کارت ها دقت کنید زیرا ممکن است تغییر کرده باشند
📸پس از واریز وجه، عکس رسید پرداختی خود را در همین قسمت ارسال کنید تا تراکنش شما بررسی و سپس در صورت صحت اطلاعات حساب کاربری تان شارژ شود
🚫تا قبل از ارسال رسید پرداختی از دکمه منو و یا بازگشت استفاده نکنید
⏳زمان بررسی تراکنش های کارت به کارت، بین 5 تا 30 دقیقه خواهد بود

توجه: لطفا از برنامه آپ برای انتقال استفاده نکنید، محدودیت داره

چنانچه در پرداخت خود با مشکل مواجه شدید، به پشتیبانی مراجعه کنید :
📞 @{$support->value}";

        $buttons[] = [
            [
                'text' => '📤 ارسال رسید',
                'callback_data' => "type=paymentSendReceipt|id={$payment->id}"
            ]
        ];
        $buttons[] = $this->clientFooterButtons("type=home");

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');

    }

    private function addFundCrypto($data)
    {

    }

    private function addFundFinal($data)
    {
        $id = $data['id'];
        $payment = Payment::find($id);
        $targetUser = User::find($payment->user_id);

        $targetUser->balance = $targetUser->balance + $payment->price;
        $targetUser->save();

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }

        $targetUserName = $targetUser->username
            ? "@{$targetUser->username}"
            : ($targetUser->first_name ?? 'بدون نام');

        $price = number_format($payment->price);

        if ($payment->method == 'cart-be-cart') {

            $adminUserName = $this->user->username
                ? "@{$this->user->username}"
                : ($this->user->first_name ?? 'ادمین');

            $caption = "✅ <b>تراکنش تایید شد</b>\n\n";
            $caption .= "━━━━━━━━━━━━━━━\n";
            $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
            $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
            $caption .= "━━━━━━━━━━━━━━━\n";
            $caption .= "💳 <b>جزئیات پرداخت</b>\n";
            $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
            $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
            $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
            $caption .= "━━━━━━━━━━━━━━━\n";
            $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
            $caption .= "📌 <b>وضعیت:</b> موفق\n";
            $adminMethod = 'edit';

            $balance = number_format($targetUser->balance);
            $userCaption = "✅ تراکنش شارژ کیف پول شما با موفقیت تایید شد.\n💰 موجودی فعلی شما: {$balance} تومان";
        }

        if ($this->isPhoto) {
            $this->telegramSdk->editCaption([
                'chat_id' => $channelId,
                'message_id' => $this->messageId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
        } else {
            $this->method = $adminMethod;
            $this->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');
        }

        $this->method = 'toUser';
        $this->sendMessage([
            'chat_id' => $targetUser->tel_id,
            'text' => $userCaption,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄خرید',
                            'callback_data' => "type=clientService",
                        ]
                    ]
                ]
            ]),

            'parse_mode' => 'HTML',
        ], 'message');
    }

    /**
     * Client Area
     */

    protected function clientService($type)
    {
        $page = $type['page'] ?? 1;

        $plan = Plans::where('type', '!=', null)->where('status', 1)->pluck('type')->toArray();
        $panel = Panels::where('panel_type', '!=', null)->where('status', 1)->pluck('type')->toArray();

        $serviceId = array_values(array_unique(array_merge(
            $plan,
            $panel
        )));

        $list = Service::orderByDesc('id')
            ->wherein('id', $serviceId)
            ->paginate(10, ['*'], 'page', $page);

        $text = headTitle("انتخاب سرویس ");
        $text .= "💡 لطفاً یکی از سرویس زیر را انتخاب کنید:";

        $keyboard = [];
        $row = [];

        foreach ($list as $country) {
            $name = !is_null($country->name) ? $country->name : 'بدون نام';
            $row[] = [
                'text' => "{$name}",
                'callback_data' => "type=clientSelectCountry|s_id={$country->id}",
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $pagination = $this->paginationFooterButton($list, $page, 'clientService');
        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons();

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectCountry($type)
    {
        $service_id = $type['s_id'];
        $page = $type['page'] ?? 1;

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }
        $panelIds = Panels::where('status', 1)
            ->where('panel_type', $service_id)
            ->pluck('id')
            ->toArray();

        $inboundCountryIds = Inbounds::whereIn('panel_id', $panelIds)
            ->whereNotNull('country_id')
            ->distinct()
            ->pluck('country_id')
            ->toArray();

        $panelCountryIds = Panels::where('status', 1)
            ->where('panel_type', $service_id)
            ->whereNotNull('country_id')
            ->distinct()
            ->pluck('country_id')
            ->toArray();

        $planCountryIds = array_values(array_unique(array_merge(
            $panelCountryIds,
            $inboundCountryIds
        )));

        $list = Countries::where('type', $service_id)
            ->where('type', $service_id)
            ->whereIn('id', $planCountryIds)
            ->paginate(10);


        $text = headTitle("🌍انتخاب کشور ");
        $text .= "📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>


💡 لطفاً یکی از کشور زیر را انتخاب کنید:";


        $keyboard = [];
        $row = [];

        if (count($list) > 0) {
            foreach ($list as $country) {
                $name = !is_null($country->name) ? $country->name : 'بدون نام';
                $row[] = [
                    'text' => "{$name}",
                    'callback_data' => "type=clientSelectPlan|s_id={$service->id}|co_id={$country->id}",
                ];
                if (count($row) === 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }

            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }

        $pagination = $this->paginationFooterButton($list, $page, "clientSelectCountry|si_id=$service_id");

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons("type=clientService");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectPlan($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $page = $type['page'] ?? 1;

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }
        $country = Countries::find($country_id);
        if (is_null($country)) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }

        $allowSellExtra = Setting::where('key', 'extra')->first();
        if (!is_null($allowSellExtra) && $allowSellExtra->value == 1) {
            $allowSellExtra = true;
        } else {
            $allowSellExtra = false;
        }

        $list = Plans::where('type', $service_id)->where('status', 1)->orderby('id')->paginate(10);

        $text = headTitle("🌍انتخاب تعرفه سرویس");
        $text .= "
📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>
🌐 <b>کشور انتخاب‌شده:</b>
<code>{$country->name}</code>
💡 لطفاً یکی از تعرفه‌های زیر را انتخاب کنید:";

        $keyboard = [];
        $row = [];

        $path = "clientSelectCount";
        if ($allowSellExtra) {
            $path = "clientSelectExtra";
        }

        if (count($list) > 0) {
            foreach ($list as $item) {
                $name = !is_null($item->name) ? $item->name : 'بدون نام';
                $price = number_format($item->price);
                $keyboard[] = [

                    [
                        'text' => "{$name} | مبلغ:$price T",
                        'callback_data' => "type=$path|s_id={$service->id}|co_id={$country->id}|pl_id={$item->id}",
                    ],
                ];

            }

        }

        $pagination = $this->paginationFooterButton($list, $page, "clientSelectPlan|si_id=$service_id|co_id=$country_id");

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons("type=clientSelectCountry|s_id=$service_id|co_id=$country_id");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function clientSelectExtra($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $plan_id = $type['pl_id'];
        $page = $type['page'] ?? 1;

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $country = Countries::find($country_id);
        if (is_null($country)) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }

        $plan = Plans::find($plan_id);
        if (is_null($plan)) {
            return $this->sendTemporaryMessage('تعرفه مورد نظر یافت نشد');
        }

        $allowSellExtra = Setting::where('key', 'extra')->first();
        if (!is_null($allowSellExtra) && $allowSellExtra->value != 1) {
            return $this->home();
        }

        $price = number_format($plan->price);
        $text = headTitle("🌍 انتخاب حجم اضافه ");
        $text = "
📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>
🌐 <b>کشور:</b>
<code>{$country->name}</code>
🌐 <b>تعرفه:</b>
<code>{$plan->name} | حجم: {$plan->bandwidth} GB | مبلغ:{$price} تومان</code>
💡 لطفاً یکی از گزینه زیر را انتخاب کنید:";

        $list = ExtraBandwidth::where('type', $service_id)->where('status', 1)->paginate(20);
        $perGbPrice = $service->price_per_gb;

        $keyboard = [];
        $row = [];
        if (count($list) > 0) {
            foreach ($list as $item) {
                $name = !is_null($item->name) ? $item->name : 'بدون نام';
                $price = calculateExtraDiscount($item, $perGbPrice);
                $price = number_format($price['price']);
                $row[] = [
                    'text' => "{$name} GB | {$price} تومان",
                    'callback_data' => "type=clientSelectCount|s_id={$service_id}|co_id={$country->id}|pl_id={$plan_id}|ex_id=$item->id",
                ];
                if (count($row) === 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }

            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }

        $pagination = $this->paginationFooterButton($list, $page, "clientSelectPlan|si_id=$service_id|co_id=$country_id");

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons("type=clientSelectPlan|s_id=$service_id|co_id=$country_id");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectCount($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $plan_id = $type['pl_id'];
        $extra = $type['ex_id'] ?? null;
        $page = $type['page'] ?? 1;
        $count = $type['cu'] ?? 1;

        if ($count <= 0) {
            return $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => "حداقل خرید یک عدد می باشد.",
                'show_alert' => true,
                'cache_time' => 1,
            ]);
        }

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $country = Countries::find($country_id);
        if (is_null($country)) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }

        $plan = Plans::find($plan_id);
        if (is_null($plan)) {
            return $this->sendTemporaryMessage('تعرفه مورد نظر یافت نشد');
        }
        $extraText = null;

        $path = "clientSelectPlan";
        if (!empty($extra)) {
            $extra = ExtraBandwidth::find($extra);
            if (is_null($plan)) {
                return $this->sendTemporaryMessage('حجم اضافه مورد نظر یافت نشد');
            }
            $extraPrice = number_format(calculateExtraDiscount($extra, $service->price_per_gb)['price']);
            $extraText = "🌐 <b>حجم اضافه انتخاب شده انتخاب‌شده:</b>
<code>{$extra->name} GB | مبلغ:{$extraPrice} تومان</code>";
            $extra = $extra->id;
            $path = "clientSelectExtra";
        }

        $price = number_format($plan->price);
        $text = headTitle("🌍انتخاب تعداد");
        $text .= "📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>
🌐 <b>کشور:</b>
<code>{$country->name}</code>
🌐 <b>تعرفه:</b>
<code>{$plan->name} | حجم: {$plan->bandwidth} GB | مبلغ:{$price} تومان</code>
{$extraText}
💡 لطفاً تعداد را مشخص کنید:";

        $decrement = max(1, $count - 1);
        $increment = min(10, $count + 1);

        $keyboard[] = [
            [
                'text' => '➖',
                'callback_data' => $count <= 1
                    ? 'ignore'
                    : "type=clientSelectCount|s_id={$service_id}|co_id={$country->id}|pl_id={$plan_id}|ex_id={$extra}|cu={$decrement}",
                'style' => 'danger',
            ],
            [
                'text' => "🛒 {$count}",
                'callback_data' => "ignore",
            ],
            [
                'text' => '➕',
                'callback_data' => $count >= 10
                    ? 'ignore'
                    : "type=clientSelectCount|s_id={$service_id}|co_id={$country->id}|pl_id={$plan_id}|ex_id={$extra}|cu={$increment}",
                'style' => 'success',
            ],
        ];

        $keyboard[] = [
            [
                'text' => 'مرحله بعد',
                'callback_data' => "type=clientSelectName|s_id={$service_id}|co_id={$country->id}|pl_id={$plan_id}|ex_id={$extra}|cu={$count}",
            ],
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=$path|s_id=$service_id|co_id=$country_id|pl_id=$plan_id|ex_id=$extra",
            ],
        ];;
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectName($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $plan_id = $type['pl_id'];
        $extra = $type['ex_id'] ?? null;
        $count = $type['cu'] ?? 1;

        $user = $this->user;

        $tel_detail = $user->tel_detail;

        $tel_detail['order-service-id'] = $service_id;
        $tel_detail['order-country-id'] = $country_id;
        $tel_detail['order-plan-id'] = $plan_id;
        $tel_detail['order-extra'] = $extra;
        $tel_detail['order-count'] = $count;

        $user->tel_detail = $tel_detail;
        $user->save();

        $this->updatePath('clientFinalStep');

        $text = headTitle("🌍انتخاب نام کانفیگ");
        $text .= "💡 لطفاً نام کانفیگ خود را وارد کنید در غیر اینصورت بر روی دکمه انتخاب خودکار کلیک کنید";


        $keyboard[] = [
            [
                'text' => 'انتخاب خودکار',
                'callback_data' => "type=clientFinalStep|name=random",
            ],
        ];
        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientSelectCount|s_id={$service_id}|co_id={$country_id}|pl_id={$plan_id}|ex_id={$extra}|cu={$count}",
            ],
        ];;
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientFinalStep($type)
    {
        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $name = $type['name'] ?? $this->text;
        $extraPrice = 0;
        if (!preg_match('/^[a-zA-Z]+$/', $name)) {
            return $this->sendTemporaryMessage(
                '❌ نام فقط باید شامل حروف انگلیسی باشد.'
            );
        }

        $service_id = $tel_detail['order-service-id'];
        $country_id = $tel_detail['order-country-id'];
        $plan_id = $tel_detail['order-plan-id'];
        $extra = $tel_detail['order-extra'];
        $count = $tel_detail['order-count'];

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $country = Countries::find($country_id);
        if (is_null($country)) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }

        $plan = Plans::find($plan_id);
        if (is_null($plan)) {
            return $this->sendTemporaryMessage('تعرفه مورد نظر یافت نشد');
        }
        $extraText = null;

        if (!empty($extra)) {
            $extra = ExtraBandwidth::find($extra);
            if (is_null($plan)) {
                return $this->sendTemporaryMessage('حجم اضافه مورد نظر یافت نشد');
            }
            $extraPrice = calculateExtraDiscount($extra, $service->price_per_gb)['price'];
            $showPrice = number_format($extraPrice);
            $extraText = "🌐 <b>حجم اضافه انتخاب شده انتخاب‌شده:</b>
<code>{$extra->name} GB | مبلغ:{$showPrice} تومان</code>";
            $extra = $extra->id;
        }

        $price = number_format($plan->price);

        $total = ($plan->price + $extraPrice) * $count;


        $preOrderData = [
            'service-id' => $service_id,
            'country-id' => $country_id,
            'plan-id' => $plan_id,
            'extra' => $extra,
            'count' => $count,
            'name' => $name,
            'price' => $total
        ];

        $preOrder = new PreOrder();
        $preOrder->user_id = $user->id;
        $preOrder->data = $preOrderData;
        $preOrder->status = 0;
        $preOrder->count = $count;
        $preOrder->count_left = $count;
        $preOrder->save();

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = $preOrder->id;
        $payment->price = $total;
        $payment->status = 0;
        $payment->type = 1;
        $payment->expired_at = Carbon::now();
        $payment->save();

        $price = number_format($payment->price);

        $text = headTitle("🌍 انتخاب نحوه پرداخت");
        $text .= "
📦 <b>نوع سرویس:</b> <code>{$service->name}</code>
🌐 <b>کشور:</b> <code>{$country->name}</code>
🌐 <b>تعرفه:</b> <code>{$plan->name}</code>
{$extraText}
🌐 <b>تعداد:</b><code>{$count} عدد</code>
💵 <b>مبلغ کل:</b> <code>{$price} تومان</code>
💡 نحوه پرداخت را مشخص کنید:";

        $keyboard[] = [
            [
                'text' => '💰 کیف پول',
                'callback_data' => "type=paymentWallet|id={$payment->id}",
            ],
        ];

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $keyboard[] = [
                [
                    'text' => '💳 کارت به کارت',
                    'callback_data' => "type=paymentCartBeCart|id={$payment->id}",
                ],
            ];
        }


        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientSelectName|s_id={$service_id}|co_id={$country->id}|pl_id={$plan_id}|ex_id={$extra}|cu={$count}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function paymentCartBeCart($type)
    {
        $id = $type['id'] ?? null;

        $this->updatePath('sendCartBeCartReceipt');


        $payment = Payment::find($id);
        $price = $payment->price ?? 0;

        if (!$payment) {
            return $this->sendTemporaryMessage('❌ پرداخت پیدا نشد');
        }

        $cartBeCartRandom = Setting::where('key', 'cart_be_cart_random')->first();

        $isRandom = ($cartBeCartRandom && (int)$cartBeCartRandom->value === 1);

        $query = Carts::where('status', 1);

        if ($isRandom) {
            $cart = (clone $query)->inRandomOrder()->first();
        } else {
            $cart = (clone $query)
                ->where('is_default', 1)
                ->orderByDesc('id')
                ->first();
            if (!$cart) {
                $cart = (clone $query)->orderByDesc('id')->first();
            }
        }

        $cardNumber = $cart->cart ?? 'اطلاعات یافت نشد';
        $cardName = $cart->name ?? '—';

        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $tel_detail['payment-id'] = $payment->id;
        $tel_detail['payment-type'] = 'cart-be-cart';
        $tel_detail['payment-cart-number'] = $cardNumber;
        $tel_detail['payment-cart-name'] = $cardName;

        $user->tel_detail = $tel_detail;
        $user->save();

        $support = Setting::where('key', 'support_id')->first();


        $rialAmount = number_format($price * 10);
        $amount = number_format($price);
        $text = "درخواست شما ثبت شد.
👝 مبلغ سفارش : <code>{$amount}</code> تومان
🔘 جهت تکمیل سفارش مبلغ فاکتور را به تومان به شماره کارت زیر واریز نموده و پس از واریز تصویر فیش را در همین مرحله برای ربات ارسال نمایید :

💳 <code> {$cardNumber} </code>
👤 به نام {$cardName}

در هنگام انتقال، از نوشتن توضیحات انتقال حاوی کلمات یا عبارات حساس مانند «وی‌پی‌ان vpn»، «کانفیگ»، «ویتوری»، «آی‌پی ثابت» و مشابه آن خودداری کنید
درصورت درج هر یک از این کلمات، حساب شما مسدود شده و کیف‌پول شارژ نخواهد شد

⚠️لطفا برای هربار انتقال به شماره کارت ها دقت کنید زیرا ممکن است تغییر کرده باشند
📸پس از واریز وجه، عکس رسید پرداختی خود را در همین قسمت ارسال کنید تا تراکنش شما بررسی و سپس در صورت صحت اطلاعات حساب کاربری تان شارژ شود
🚫تا قبل از ارسال رسید پرداختی از دکمه منو و یا بازگشت استفاده نکنید
⏳زمان بررسی تراکنش های کارت به کارت، بین 5 تا 30 دقیقه خواهد بود

توجه: لطفا از برنامه آپ برای انتقال استفاده نکنید، محدودیت داره

توجه: لطفا از برنامه آپ برای انتقال استفاده نکنید، محدودیت داره
چنانچه در پرداخت خود با مشکل مواجه شدید، به پشتیبانی مراجعه کنید :
📞 @{$support->value}";


        $buttons[] = [
            [
                'text' => '📤 ارسال رسید',
                'callback_data' => "type=paymentSendReceipt|id={$payment->id}"
            ]
        ];

        $buttons[] = [
            [
                'text' => 'منو اصلی',
                'callback_data' => "type=home",
                'style' => 'primary'
            ]
        ];

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');
    }

    protected function paymentSendReceipt($type)
    {

        $this->updatePath('sendCartBeCartReceipt');
        $text = headTitle('💳 پرداخت کارت به کارت');
        $text .= "\n لطفا عکس رسید خود را ارسال کنید. \n";

        $buttons[] = [
            [
                'text' => 'منو اصلی',
                'callback_data' => "type=home",
                'style' => 'primary'
            ]
        ];

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');

    }

    protected function paymentSubmitCartBeCartReceipt()
    {
        $user = $this->user;
        $tel_detail = $user->tel_detail ?? [];

        $paymentId = $tel_detail['payment-id'] ?? null;
        $paymentCardNumber = $tel_detail['payment-cart-number'] ?? null;
        $paymentCardName = $tel_detail['payment-cart-name'] ?? null;

//        $this->updatePath('start');

        if (empty($this->messageId)) {
            return $this->sendTemporaryMessage('❌ پیام یا رسیدی ارسال نشده است.');
        }


        $channelId = null;

        // تنظیمات کانال تایید تراکنش
        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        if (
            !is_null($transactionChannel) &&
            !empty($transactionChannel->value)
        ) {
            $channelId = $transactionChannel->value;
        } else {
            $admin = User::where('is_admin', 1)->first();
            if ($admin) {
                $channelId = $admin->tel_id;
            }
        }
        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }

        if ($this->fileId == false) {
            $value = $this->text;
        } else {
            $fileId = $this->fileId;
            $fileName = 'telegram_' . time() . '_' . rand(1000, 9999) . '.jpg';
            $savePath = base_path('../public_html/uploads/telegram/' . $fileName);
            $download = $this->telegramSdk->downloadFileById($fileId, $savePath);
            if ($download['ok']) {
                $value = url('uploads/telegram/' . $fileName);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Forward receipt
        |--------------------------------------------------------------------------
        */

//        $data = [
//            'chat_id' => $channelId,
//            'from_chat_id' => $this->chatId,
//            'message_id' => $this->messageId,
//        ];
//
//        $this->telegramSdk->forwardMessage($data);
        /*
        |--------------------------------------------------------------------------
        | Send info message to admin
        |--------------------------------------------------------------------------
        */

        $caption = "💳 <b>رسید جدید کارت به کارت</b>\n\n";

        $payment = Payment::find($paymentId);
        $paymentType = __('payment.type.' . $payment->type);
        $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";

        $paymentDetail = $payment->detail;
        $paymentDetail['cart-number'] = $tel_detail['payment-cart-number'];
        $paymentDetail['cart-name'] = $tel_detail['payment-cart-name'];
        $paymentDetail['value'] = $value;


        $payment->method = 'cart-be-cart';
        $payment->detail = $paymentDetail;
        $payment->save();

        $Price = $payment->price;

        $Price = number_format($Price);
        $caption .= "👤 کاربر\n";
        if (!empty($user->username)) {
            $caption .= "@{$user->username}\n";
        } else {
            $caption .= "{$user->first_name}\n";
        }
        $caption .= " آیدی: <code>{$user->tel_id}</code>\n\n";
        $caption .= "اطلاعات پرداخت\n";
        $caption .= "💰 شماره تراکنش: <code>{$payment->id}</code>\n";
        $caption .= "💰 شماره کارت: <code>{$paymentCardNumber}</code>\n";
        $caption .= "👤 صاحب کارت: {$paymentCardName}\n";
        $caption .= "🧾 مبلغ تراکنش: <code>{$Price}</code> تومان\n\n";
        $caption .= "رسید کاربر: \n";


        $this->method = 'toUser';

        /*
        |--------------------------------------------------------------------------
        | Response to user
        |--------------------------------------------------------------------------
        */
        if ($this->fileId == false) {
            $caption .= $value;
            $this->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '✅ تایید پرداخت',
                                'callback_data' => "type=adminConfirmCartReceipt|p_id={$paymentId}",
                                'style' => 'success'
                            ],
                            [
                                'text' => '❌ رد پرداخت',
                                'callback_data' => "type=adminRejectCartReceipt|p_id={$paymentId}",
                                'style' => 'danger'
                            ]
                        ]
                    ]
                ])
            ], 'message');
        } else {
            $this->telegramSdk->sendPhoto([
                'chat_id' => $channelId,
                'photo' => url($value),
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '✅ تایید پرداخت',
                                'callback_data' => "type=adminConfirmCartReceipt|p_id={$paymentId}",
                                'style' => 'success'
                            ],
                            [
                                'text' => '❌ رد پرداخت',
                                'callback_data' => "type=adminRejectCartReceipt|p_id={$paymentId}",
                                'style' => 'danger'
                            ]
                        ]
                    ]
                ])
            ]);
        }

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => "✅ رسید شما با موفقیت ارسال شد و پس از بررسی تایید خواهد شد.",
        ], 'message');
    }

    protected function adminRejectCartReceipt($type)
    {
        $id = $type['p_id'];

        $payment = Payment::find($id);

        if (!$payment) {
            return $this->sendTemporaryMessage('❌ تراکنش یافت نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Update Status
        |--------------------------------------------------------------------------
        */

        $payment->status = -1;
        $payment->save();

        /*
        |--------------------------------------------------------------------------
        | Channel ID
        |--------------------------------------------------------------------------
        */

        $channelId = null;

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $support_id = Setting::where('key', 'support_id')->first();


        if (
            !is_null($transactionChannel) &&
            !empty($transactionChannel->value)
        ) {
            $channelId = $transactionChannel->value;
        } else {

            $admin = User::where('is_admin', 1)->first();

            if ($admin) {
                $channelId = $admin->tel_id;
            }
        }

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال گزارش یافت نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        $adminUser = $this->user;
        $targetUser = User::find($payment->user_id);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('❌ کاربر تراکنش یافت نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Details
        |--------------------------------------------------------------------------
        */

        $price = number_format($payment->price);

        $paymentCardNumber = $payment->detail['cart-number'] ?? '—';
        $paymentCardName = $payment->detail['cart-name'] ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Usernames
        |--------------------------------------------------------------------------
        */

        $targetUserName = !empty($targetUser->username)
            ? "@{$targetUser->username}"
            : ($targetUser->first_name ?? 'بدون نام');

        $adminUserName = !empty($adminUser->username)
            ? "@{$adminUser->username}"
            : ($adminUser->first_name ?? 'ادمین');

        /*
        |--------------------------------------------------------------------------
        | Caption
        |--------------------------------------------------------------------------
        */

        $caption = "❌ <b>تراکنش کارت به کارت رد شد</b>\n\n";

        $caption .= "━━━━━━━━━━━━━━━\n";
        $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
        $caption .= "🆔 <b>آیدی تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
        $caption .= "━━━━━━━━━━━━━━━\n";

        $caption .= "💳 <b>اطلاعات پرداخت</b>\n";
        $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
        $caption .= "💳 <b>شماره کارت:</b> <code>{$paymentCardNumber}</code>\n";
        $caption .= "👤 <b>صاحب کارت:</b> {$paymentCardName}\n";
        $caption .= "💰 <b>مبلغ:</b> <code>{$price}</code> تومان\n";

        $caption .= "━━━━━━━━━━━━━━━\n";
        $caption .= "🚫 <b>رد شده توسط:</b>\n{$adminUserName}";


        if ($this->isPhoto) {
            $this->telegramSdk->editCaption([
                'chat_id' => $channelId,
                'message_id' => $this->messageId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
        } else {
            $this->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',

            ], 'message');
        }
        $userText = "❌ <b>پرداخت شما تایید نشد</b>\n\n";
        $userText .= "🔢 شماره تراکنش: <code>{$payment->id}</code>\n";
        $userText .= "💰 مبلغ: <code>{$price}</code> تومان\n\n";
        $userText .= "در صورت نیاز با پشتیبانی ارتباط بگیرید.";
        if (!is_null($support_id) && $support_id->value != 0) {
            $buttons[] = [
                [
                    'text' => 'پشتیبانی',
                    'url' => "https://t.me/$support_id->value",
                ]
            ];
        }
        $buttons[] = [
            [
                'text' => 'منو اصلی',
                'callback_data' => "type=home",
                'style' => 'primary'
            ]
        ];
        $this->method = 'toUser';
        return $this->sendMessage([
            'chat_id' => $targetUser->tel_id,
            'text' => $userText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');
    }

    protected function adminConfirmCartReceipt($type)
    {
        $id = $type['p_id'];

        $payment = Payment::find($id);
        if (is_null($payment)) {
            return $this->sendTemporaryMessage('تراکنش یافت نشد');
        }

        if ($payment->status == 0) {
            $payment->status = 1;
            $payment->save();
            return $this->finalPaymentStep($payment);
        }
    }

    protected function paymentWallet($type)
    {
        $id = $type['id'];
        $user = $this->user;
        $payment = Payment::find($id);

        if (is_null($payment)) {
            return $this->sendTemporaryMessage('تراکنش یافت نشد');
        }
        if ($user->balance < $payment->price) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'موجودی شما برای پرداخت این سفارش کافی نیست.',
                'show_alert' => true,
                'cache_time' => 1,
            ];
            return $this->telegramSdk->answerCallback($data);
        }


        if ($payment->status == 0) {
            $user->decrement('balance', $payment->price);
            $payment->status = 1;
            $payment->method = 'wallet';
            $payment->save();
            return $this->finalPaymentStep($payment);
        }

    }

    protected function finalPaymentStep($payment)
    {
        switch ($payment->type) {
            case "1":
                return $this->submitOrder($payment->id, 'toUser');
                break;
            case "2":
                return $this->clientFinalRenew(['id' => $payment->id]);
                break;
            case "3":
                return $this->clientFinalExtra(['id' => $payment->id]);
                break;
            case "4":
                return $this->addFundFinal(['id' => $payment->id]);
                break;
        }
    }

    private function submitOrder($payment)
    {

        $payment = Payment::find($payment);
        $targetUser = User::find($payment->user_id);
        $adminMethod = 'toUser';
        $userMethod = 'toUser';

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();

        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }

        if ($payment->method == 'cart-be-cart') {
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال تحویل سفارش به کاربر هستیم...\nلطفاً چند لحظه صبر کنید.\nشناسه کاربر:  <code>{$targetUser->tel_id}</code>\nشماره تراکنش:<code>{$payment->id}</code>\n";
            $adminMethod = 'edit';
            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
        if ($payment->method == 'wallet') {

            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال پردازش سفارش هستیم...\nلطفاً چند لحظه صبر کنید.";

            $this->sendMessage([
                'chat_id' => $targetUser->tel_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');

            $userMethod = 'edit';
        }

        /*
        |--------------------------------------------------------------------------
        | Load Models
        |--------------------------------------------------------------------------
        */

        $preOrder = PreOrder::find($payment->order_id);
        $targetUser = User::find($payment->user_id);

        $orderDetail = $preOrder->data;

        $plan = Plans::find($orderDetail['plan-id']);
        $panel = Panels::where('country_id', $orderDetail['country-id'])
            ->where('panel_type', $plan->type)
            ->where('system_type', 'sanaie')
            ->where('status', 1)
            ->first();

        if (!is_null($panel)) {
            $activeInbound = Inbounds::where('panel_id', $panel->id)
                ->where('status', 1)
                ->first();

            if (!is_null($activeInbound)) {
                $data['panel'] = $panel;
            } else {

                $activeInbound = Inbounds::where('country_id', $orderDetail['country-id'])
                    ->where('status', 1)
                    ->first();
                if (!is_null($activeInbound)) {
                    $data['panel'] = Panels::find($activeInbound->panel_id);
                }
            }
        } else {
            $activeInbound = Inbounds::where('country_id', $orderDetail['country-id'])
                ->where('status', 1)
                ->first();

            if (!is_null($activeInbound)) {
                $data['panel'] = Panels::find($activeInbound->panel_id);
            }
        }
        $data['targetUser'] = $targetUser;
        $data['payment'] = $payment;
        $data['preOrder'] = $preOrder;
        $data['adminMethod'] = $adminMethod;
        $data['userMethod'] = $userMethod;
        $data['channelId'] = $channelId;
        $data['orderDetail'] = $orderDetail;
        $data['plan'] = $plan;

        return $this->generateAccount($data);
    }

    private function generateAccount($data)
    {
        $panel = $data['panel'];
        $targetUser = $data['targetUser'];
        $payment = $data['payment'];
        $preOrder = $data['preOrder'];
        $adminMethod = $data['adminMethod'];
        $userMethod = $data['userMethod'];
        $channelId = $data['channelId'];
        $orderDetail = $data['orderDetail'];
        $plan = $data['plan'];


        if ($panel->system_type == 'pasarguard') {


            $activeGroup = Inbounds::where('panel_id', $panel->id)
                ->where('country_id', $orderDetail['country-id'])
                ->where('status', 1)
                ->first();

            $pasarGuard = new PasarGuard([
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ]);

            if (!$pasarGuard->checkConnection()) {
                return [
                    'status' => false,
                    'message' => $pasarGuard->getLoginStatus()['message'],
                ];
            }

            $leftCount = (int)$preOrder->count_left;

            $successCount = 0;
            $orders = [];

            for ($i = 0; $i < $leftCount; $i++) {

                $remarkBase = $orderDetail['name'] !== 'random' ? $orderDetail['name'] : "user-{$targetUser->tel_id}";

                $remark = $remarkBase . '-' . rand(1111, 9999);

                $bandwidth = (int)$plan->bandwidth;

                if ($extra = ExtraBandwidth::find($orderDetail['extra'] ?? null)) {
                    $bandwidth += (int)$extra->bandwidth;
                }


                $result = $pasarGuard->createUserAndGetConfig([
                    'username' => $remark,
                    'group_id' => $activeGroup->inbound_id,
                    'days' => (int)$plan->days,
                    'total_gb' => $bandwidth,
                    'client_type' => 'links',
                    'note' => 'Created from Telegram bot',
                    'status' => "on_hold",
                    "on_hold_timeout" => 0,
                    'on_hold_expire_duration' => (int)$plan->days * 24 * 60 * 60,
                    'auto_delete_in_days' => 7
                ]);

                if (!$result['status']) {
                    return $result;
                }

                $config = $result['config'];

                $Order = Orders::create([
                    'user_id' => $payment->user_id,
                    'remark' => $remark,
                    'uid' => $result['user']['id'],
                    'sub_id' => $result['user']['subscription_url'],
                    'plan' => $plan->id,
                    'panel_id' => $panel->id,
                    'inbound_id' => $activeGroup->id,
                    'system_type' => 'pasarguard',
                    'expire_at' => Carbon::now()->addDays((int)$plan->days)->format('Y-m-d H:i:s'),
                    'status' => 1,
                    'detail' => [
                        'code' => $config,
                        'preOrderId' => $preOrder->id,
                    ],
                ]);

                $orders[] = [
                    'order-id' => $Order->id,
                    'code' => $config,
                    'sub' => "{$panel->sub_address}/{$Order->sub_id}",
                    'remark' => $Order->remark,
                ];
                $successCount++;
            }

            $preOrder->update([
                'status' => $successCount == $preOrder->count ? 1 : 0,
                'count_left' => max(0, $preOrder->count_left - $successCount),
            ]);

            foreach ($orders as $singleOrder) {
                $photo = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($singleOrder['code']);
                $this->telegramSdk->sendPhoto([
                    'chat_id' => $targetUser->tel_id,
                    'photo' => $photo,
                    'caption' => "
<b>✅ سفارش با موفقیت تکمیل شد</b>

🧾 شماره سفارش:
<code>{$singleOrder['order-id']}</code>

🔑 کد کانفیگ:
<code>{$singleOrder['code']}</code>

🔗 لینک ساب:
<code>{$singleOrder['sub']}</code>
",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$singleOrder['order-id']}",
                                ]
                            ]
                        ]
                    ]),
                ]);
            }

            $remarks = collect($orders)
                ->pluck('remark')
                ->filter()
                ->map(fn($r) => "<code>{$r}</code>")
                ->implode(', ');

            $targetUserName = $targetUser->username
                ? "@{$targetUser->username}"
                : ($targetUser->first_name ?? 'بدون نام');

            $price = number_format($payment->price);
            $paymentType = __('payment.type.' . $payment->type);

            if ($payment->method == 'cart-be-cart') {

                $adminUserName = $this->user->username
                    ? "@{$this->user->username}"
                    : ($this->user->first_name ?? 'ادمین');

                $caption = "✅ <b>تراکنش تایید شد</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
                $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                $caption .= "📌 <b>وضعیت:</b> موفق\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "🔗<b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            } elseif ($payment->method == 'wallet') {

                $this->deleteChat();
                $caption = "✅ <b>خرید آی پی</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "🔗<b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            }

            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }

        } else {
            $session['session'] = "";
            if (!is_null($panel)) {
                $session = loginToSanaie([
                    'url' => $panel->url,
                    'username' => $panel->username,
                    'password' => $panel->password,
                ]);
            }

            if (empty($session['session'])) {

                $targetUser->increment('balance', $payment->price);

                $payment->update(['status' => -2]);
                $preOrder->update(['status' => -2]);

                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => "❌ خطا در اتصال به سرور رخ داده است.\n\n🏷 نام سرور: {$panel->name}",
                    'parse_mode' => 'HTML',
                ], 'message');

                $this->method = $userMethod;
                return $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => "❌ عملیات تحویل سفارش انجام نشد.\n\n💰 مبلغ <code>{$payment->price}</code> تومان به کیف پول شما بازگردانده شد.",
                    'parse_mode' => 'HTML',
                ], 'message');
            }

            /*
            |--------------------------------------------------------------------------
            | Get Inbound
            |--------------------------------------------------------------------------
            */

            $activeInbound = Inbounds::where('panel_id', $panel->id)
                ->where('status', 1)
                ->first();

            $inbound = json_decode($activeInbound->setting, true);
            if (!$inbound) {
                return $this->sendTemporaryMessage('❌ اینباند یافت نشد.');
            }


            $inbound['settings'] = json_decode($inbound['settings'] ?? '{}', true);
            $inbound['streamSettings'] = json_decode($inbound['streamSettings'] ?? '{}', true);
            $inbound['sniffing'] = json_decode($inbound['sniffing'] ?? '{}', true);
            /*
            |--------------------------------------------------------------------------
            | Create Clients
            |--------------------------------------------------------------------------
            */

            $leftCount = (int)$preOrder->count_left;
            $successCount = 0;
            $orders = [];

            $address = $session['raw']['Domain'] ?? null;

            for ($i = 0; $i < $leftCount; $i++) {

                $remarkBase = $orderDetail['name'] === 'random'
                    ? ($targetUser->username ?? 'user')
                    : $orderDetail['name'];

                $remark = $remarkBase . '-' . rand(1111, 9999);

                $bandwidth = (int)$plan->bandwidth;

                if ($extra = ExtraBandwidth::find($orderDetail['extra'] ?? null)) {
                    $bandwidth += (int)$extra->bandwidth;
                }

                $expireTime = Carbon::now()
                        ->addDays((int)$plan->days)
                        ->timestamp * 1000;

                $addClientData = [
                    'serverUrl' => $panel->url,
                    'sessionCookie' => $session['session'],
                    'inboundId' => $inbound['id'],
                    'email' => $remark,
                    'uuid' => (string)Str::uuid(),
                    'subId' => Str::random(16),
                    'expiryTimestamp' => $expireTime,
                    'limitIp' => 0,
                    'totalGB' => gbToByte($bandwidth),
                ];
                $addClient = addClient($addClientData);

                if (!($addClient['success'] ?? false)) {
                    continue;
                }

                $code = makeSanaeiVlessConfig($inbound['streamSettings'], $addClientData['uuid'], $remark);

                $Order = Orders::create([
                    'user_id' => $payment->user_id,
                    'remark' => $remark,
                    'uid' => $addClientData['uuid'],
                    'sub_id' => $addClientData['subId'],
                    'plan' => $plan->id,
                    'panel_id' => $panel->id,
                    'system_type' => 'sanaie',
                    'inbound_id' => $activeInbound->id,
                    'status' => 1,
                    'expire_at' => Carbon::now()->addDays($plan->days)->format('Y-m-d H:i:s'),
                    'detail' => [
                        'code' => $code,
                        'preOrderId' => $preOrder->id,
                    ],
                ]);

                $orders[] = [
                    'order-id' => $Order->id,
                    'code' => $code,
                    'sub' => "{$panel->sub_address}/sub/{$Order->sub_id}",
                    'remark' => $Order->remark,
                ];
                $successCount++;
            }

            /*
            |--------------------------------------------------------------------------
            | Update PreOrder
            |--------------------------------------------------------------------------
            */

            $preOrder->update([
                'status' => $successCount == $preOrder->count ? 1 : 0,
                'count_left' => max(0, $preOrder->count_left - $successCount),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Send Orders to User
            |--------------------------------------------------------------------------
            */

            foreach ($orders as $singleOrder) {
                $photo = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($singleOrder['code']);
                $this->telegramSdk->sendPhoto([
                    'chat_id' => $targetUser->tel_id,
                    'photo' => $photo,
                    'caption' => "
<b>✅ سفارش با موفقیت تکمیل شد</b>

🧾 شماره سفارش:
<code>{$singleOrder['order-id']}</code>

🔑 کد کانفیگ:
<code>{$singleOrder['code']}</code>

🔗 لینک ساب:
<code>{$singleOrder['sub']}</code>
",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$singleOrder['order-id']}",
                                ]
                            ]
                        ]
                    ]),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Final Report
            |--------------------------------------------------------------------------
            */

            $remarks = collect($orders)
                ->pluck('remark')
                ->filter()
                ->map(fn($r) => "<code>{$r}</code>")
                ->implode(', ');

            $targetUserName = $targetUser->username
                ? "@{$targetUser->username}"
                : ($targetUser->first_name ?? 'بدون نام');

            $price = number_format($payment->price);

            if ($payment->method == 'cart-be-cart') {

                $adminUserName = $this->user->username
                    ? "@{$this->user->username}"
                    : ($this->user->first_name ?? 'ادمین');

                $caption = "✅ <b>تراکنش تایید شد</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                $caption .= "📌 <b>وضعیت:</b> موفق\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            } elseif ($payment->method == 'wallet') {

                $this->deleteChat();
                $caption = "✅ <b>خرید آی پی</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            }

            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
    }

    protected function clientOrders($data)
    {
        $page = $data['page'] ?? 1;
        $status = $data['status'] ?? 'all';
        $search = $data['search'] ?? null;

        $user = $this->user;

        $query = Orders::where('user_id', $user->id);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (!empty($search)) {
            $query->where('remark', 'like', "%{$search}%")
                ->orWhere('detail->code', $search);
        }

        $list = $query
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("📦 لیست سفارش‌ها");

        if ($list->count() == 0) {
            $text .= "❌ موردی یافت نشد.";
        } else {

            $text .= "برای مشاهده جزئیات روی سفارش کلیک کنید 👇\n\n";
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons (Orders list)
        |--------------------------------------------------------------------------
        */

        $keyboard = ['inline_keyboard' => []];

        if ($list->count() > 0) {

            foreach ($list as $order) {
                $keyboard['inline_keyboard'][] = [
                    [
                        'text' => "🧾 سفارش #{$order->id} | {$order->remark}",
                        'callback_data' => "type=clientSingleOrder|id={$order->id}"
                    ]
                ];
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'clientOrders');
        if (!empty($pagination)) {
            $keyboard['inline_keyboard'][] = $pagination;
        }

        $keyboard['inline_keyboard'][] = [['text' => 'منو', 'callback_data' => 'type=home']];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSingleOrder($data)
    {
        $id = $data['id'] ?? null;
        $user = $this->user;

        if (!$id) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $user->tel_id,
                'text' => "❌ <b>سفارش نامعتبر است!</b>\n\nلطفا دوباره از بخش «سرویس های من» سفارش خود را انتخاب کنید.",
                'parse_mode' => 'HTML',
            ]);
        }

        $order = Orders::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (is_null($order)) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $user->tel_id,
                'text' => "🚫 <b>سفارش یافت نشد</b>\n\nاین سفارش وجود ندارد یا متعلق به حساب شما نیست.",
                'parse_mode' => 'HTML',
            ]);
        }

        $detail = is_array($order->detail)
            ? $order->detail
            : json_decode($order->detail, true);

        $buttons = [];

        $allowSellExtra = Setting::where('key', 'extra')->value('value') == 1;

        $buttons[] = [
            [
                'text' => '✏️ تغییر نام',
                'callback_data' => "type=clientChangeConfigName|id={$order->id}",
            ],
            [
                'text' => '🔗 تغییر کد',
                'callback_data' => "type=clientChangeConfigUid|id={$order->id}",
            ],
        ];


        if ($allowSellExtra) {
            $buttons[] = [
                [
                    'text' => '➕ خرید حجم',
                    'callback_data' => "type=clientBuyExtra|id={$order->id}",
                ],
                [
                    'text' => '🔄 تمدید سرویس',
                    'callback_data' => "type=clientRenewOrder|id={$order->id}",
                ],
            ];

            $buttons[] = [
                [
                    'text' => '📚 فایل های راهنما',
                    'callback_data' => "type=clientGuides|id={$order->id}",
                ],
            ];
        } else {
            $buttons[] = [
                [
                    'text' => '📚 فایل های راهنما',
                    'callback_data' => "type=clientGuides|id={$order->id}",
                ],
                [
                    'text' => '🔄 تمدید سرویس',
                    'callback_data' => "type=clientRenewOrder|id={$order->id}",
                ],
            ];
        }

        $buttons[] = [

            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=home|action=delete',
            ],
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=clientOrders|action=delete',
            ],

        ];

        $panel = Panels::find($order->panel_id);

        if (is_null($panel)) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $user->tel_id,
                'text' => "⚠️ <b>خطا در دریافت اطلاعات سرویس</b>\n\nپنل مربوط به این سفارش پیدا نشد. لطفا با پشتیبانی در ارتباط باشید.",
                'parse_mode' => 'HTML',
            ]);
        }

        $jdf = new Jdf();
        $expireTime = $order->expire_at ? $jdf->jdate('H:i:s d-m-Y', strtotime($order->expire_at)) : 'اطلاعات یافت نشد';

        $data = getConfigDetail($order);
        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $totalUsed = $data['data']['totalUsed'];
            $left = $data['data']['left'];
            $code = $data['data']['code'];
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }

        $configCodeRaw = $code ?? '-';

        $subUrl = rtrim($panel->sub_address, '/') . $order->sub_id;

        $configCode = htmlspecialchars($configCodeRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subUrlSafe = htmlspecialchars($subUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message = "<b>✅ جزئیات سفارش #{$order->id}</b>\n\n";
        $message .= "<b>حجم کل:</b> {$totalGb} گیگ\n";
        $message .= "<b>حجم مصرف شده:</b> {$totalUsed} گیگ\n";
        $message .= "<b>حجم باقی مانده:</b> {$left} گیگ\n";
        $message .= "<b>زمان پایان:</b> {$expireTime}\n\n";
        $message .= "<b>کد کانفیگ:</b>\n<code>{$configCode}</code>\n\n";
        $message .= "<b>لینک ساب:</b>\n<code>{$subUrlSafe}</code>";

        /*
         * نکته مهم:
         * caption در sendPhoto محدودیت دارد.
         * اگر متن طولانی شد، اول QR را می فرستیم، بعد متن کامل را با sendMessage ارسال می کنیم.
         */
        $photo = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($code);

        if (mb_strlen(strip_tags($message), 'UTF-8') <= 900) {
            return $this->telegramSdk->sendPhoto([
                'chat_id' => $user->tel_id,
                'photo' => $photo,
                'caption' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => $buttons,
                ]),
            ]);
        }

        return $this->telegramSdk->sendPhoto([
            'chat_id' => $user->tel_id,
            'photo' => $photo,
            'caption' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons,
            ]),
        ]);
    }

    protected function clientChangeConfigName($data)
    {
        $orderId = $data['id'];
        $this->updatePath('clientChangeConfigNameSubmit');
        $user = $this->user;

        $order = Orders::find($orderId);
        $panel = Panels::find($order->panel_id);

        if ($panel->system_type == 'pasarguard') {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر نام وجود ندارد',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }
        $this->deleteChat();

        $telDetail = $user->tel_detail ?? [];
        $telDetail['order-id'] = $orderId;
        $user->tel_detail = $telDetail;
        $user->save();

        $text = "⚙️ <b>تغییر نام کانفیگ</b>\n\n";
        $text .= " مقدار قبلی : <b>{$order->remark}</b>\n\n";

        $buttons = [];

        $buttons[] = $this->adminFooterButtons("type=clientSingleOrder|id={$order->id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientChangeConfigNameSubmit($data)
    {
        $name = $data['name'];
        if (!preg_match('/^[a-zA-Z]+$/', $name)) {
            return $this->sendTemporaryMessage(
                '❌ نام فقط باید شامل حروف انگلیسی باشد.'
            );
        }

        $name = $name . '-' . rand(100, 999);

        $user = $this->user;
        $userDetail = $user->tel_detail;

        $order = Orders::find($userDetail['order-id']);

        $panel = Panels::find($order->panel_id);
        $inbound = Inbounds::find($order->inbound_id);
        $loginData = [
            'username' => $panel->username,
            'password' => $panel->password,
            'url' => $panel->url,
        ];
        $session = loginToSanaie($loginData);

        $clientRequestData = [
            'sessionCookie' => $session['session'],
            'serverUrl' => $panel->url,
            'uuid' => $order->uid,
        ];

        $clientData = getClient($clientRequestData)['obj'][0];
        $result = [
            'serverUrl' => $panel->url,
            'sessionCookie' => $session['session'],
            'inboundId' => $clientData['inboundId'],
            'oldUuid' => $order->uid,
            'uuid' => $order->uid,
            'email' => $name,
            'expiryTimestamp' => $clientData['expiryTime'],
            'limitIp' => 0,
            'subId' => $clientData['subId'],
            'totalGB' => $clientData['total'],
        ];

        $result = updateClient($result);
        if ($result['success']) {

            $code = makeSanaeiVlessConfig(json_decode($inbound->setting, true)['streamSettings'], $order->uid, $name);

            $orderDetail = $order->detail;
            $orderDetail['code'] = $code;

            $order->remark = $name;
            $order->detail = $orderDetail;
            $order->save();
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'نام کانفیگ با موفقیت تغییر پیدا کرد.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            return $this->clientSingleOrder($data);
        } else {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'خطا در تغییر نام کانفیگ',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            return $this->clientSingleOrder($data);
        }

    }

    protected function clientChangeConfigUid($data)
    {
        $id = $data['id'];
        $uid = (string)Str::uuid();

        $order = Orders::find($id);
        $panel = Panels::find($order->panel_id);
        if ($panel->system_type == 'pasarguard') {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر کد وجود ندارد',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        $name = $order->remark;

        $panel = Panels::find($order->panel_id);
        $inbound = Inbounds::find($order->inbound_id);
        $loginData = [
            'username' => $panel->username,
            'password' => $panel->password,
            'url' => $panel->url,
        ];
        $session = loginToSanaie($loginData);
        $clientRequestData = [
            'sessionCookie' => $session['session'],
            'serverUrl' => $panel->url,
            'uuid' => $order->uid,
        ];
        $clientData = getClient($clientRequestData)['obj'][0];

        $result = [
            'serverUrl' => $panel->url,
            'sessionCookie' => $session['session'],
            'inboundId' => $clientData['inboundId'],
            'oldUuid' => $order->uid,
            'uuid' => $uid,
            'email' => $name,
            'expiryTimestamp' => $clientData['expiryTime'],
            'limitIp' => 0,
            'subId' => $clientData['subId'],
            'totalGB' => $clientData['total'],
        ];

        $result = updateClient($result);
        if ($result['success']) {

            $code = makeSanaeiVlessConfig(json_decode($inbound->setting, true)['streamSettings'], $uid, $name);

            $orderDetail = $order->detail;
            $orderDetail['code'] = $code;

            $order->uid = $uid;
            $order->detail = $orderDetail;
            $order->save();
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'کد کانفیگ با موفقیت تغییر پیدا کرد.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            $this->deleteChat();
            return $this->clientSingleOrder($data);
        } else {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'خطا در تغییر کد کانفیگ',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            return $this->clientSingleOrder($data);
        }

    }

    protected function clientRenewOrder($data)
    {

        $orderid = $data['id'];

        $order = Orders::find($orderid);

        $panel = Panels::find($order->panel_id);

        $plans = Plans::where('type', $panel->panel_type)->where('status', 1)->orderby('id')->get();

        if (count($plans) > 0) {
            foreach ($plans as $item) {
                $name = !is_null($item->name) ? $item->name : 'بدون نام';
                $price = number_format($item->price);
                $keyboard[] = [

                    [
                        'text' => "{$name} | مبلغ:$price T",
                        'callback_data' => "type=clientSubmitRenew|o_id={$order->id}|pl_id={$item->id}",
                    ],
                ];
            }
        }
        $text = headTitle('تمدید سرویس');
        $text .= "
💡 لطفاً یکی از تعرفه‌های زیر را انتخاب کنید:";

        $keyboard[] = $this->clientFooterButtons("type=clientSingleOrder|id={$order->id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->deleteChat();
        $this->method = 'toUser';
        return $this->sendMessage($data, 'message');

    }

    protected function clientSubmitRenew($data)
    {

        $orderId = $data['o_id'];
        $planId = $data['pl_id'];
        $user = $this->user;

        $plan = Plans::find($planId);
        $price = number_format($plan->price);

        $detail['plan-id'] = $plan->id;

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = $orderId;
        $payment->price = $plan->price;
        $payment->status = 0;
        $payment->detail = $detail;
        $payment->type = 2;
        $payment->expired_at = Carbon::now()->addMinutes(10);
        $payment->save();

        $text = headTitle("🌍انتخاب نحوه پرداخت");
        $text .= "🌐 <b>تعرفه:</b>
<code>{$plan->name} | حجم: {$plan->bandwidth} GB | مبلغ:{$price} تومان</code>
💡 نحوه پرداخت را مشخص کنید:";

        $keyboard[] = [
            [
                'text' => 'کیف پول',
                'callback_data' => "type=paymentWallet|id={$payment->id}",
            ],
        ];

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $keyboard[] = [
                [
                    'text' => 'کارت به کارت',
                    'callback_data' => "type=paymentCartBeCart|id={$payment->id}",
                ],
            ];
        }

        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientRenewOrder|id={$orderId}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientFinalRenew($data)
    {
        $payment = Payment::find($data['id']);
        $targetUser = User::find($payment->user_id);

        $adminMethod = 'toUser';
        $userMethod = 'toUser';

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }
        if ($payment->method == 'cart-be-cart') {
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال تحویل سفارش به کاربر هستیم...\nلطفاً چند لحظه صبر کنید.";
            $adminMethod = 'edit';
            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = 'edit';
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
        if ($payment->method == 'wallet') {

            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال پردازش سفارش هستیم...\nلطفاً چند لحظه صبر کنید.";

            $this->sendMessage([
                'chat_id' => $targetUser->tel_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');

            $userMethod = 'edit';
        }

        $order = Orders::find($payment->order_id);

        $plan = Plans::find($payment->detail['plan-id']);
        $panel = Panels::find($order->panel_id);

        return $this->renewClient($panel, $order, $plan, $targetUser, $payment, $adminMethod, $channelId);
    }

    protected function renewClient($panel, $order, $plan, $targetUser, $payment, $adminMethod, $channelId)
    {
        $paymentType = __('payment.type.' . $payment->type);
        if ($panel->system_type == 'pasarguard') {


            $pasarGuard = new PasarGuard([
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ]);
            if (!$pasarGuard->checkConnection()) {
                return [
                    'status' => false,
                    'message' => $pasarGuard->getLoginStatus()['message'],
                ];
            }
            $result = $pasarGuard->getUserById($order->uid);

            $expire = Carbon::parse($result['expire'])->addDays((int)$plan->days)->format('Y-m-d H:i:s');
            $band = gbToByte($plan->bandwidth);

            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => $result['data_limit'] + $band,
            ];

            $result = $pasarGuard->updateUserById($order->uid, $data);

            if ($result['status'] != false) {

                $order->expire_at = $expire;
                $order->status = 1;
                $order->reminded = 0;
                $order->save();

                $caption = "تمدید سرویس با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');


                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>تمدید تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {
                    $this->deleteChat();
                    $caption = "✅ <b>تمدید آی پی</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }


                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }
            } else {

                $targetUser->balance = $payment->price + $targetUser->balance;
                $targetUser->save();

                $caption = "❌ <b>تمدید سرویس ناموفق بود</b>

متاسفانه عملیات تمدید سرویس شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ تمدید سرویس ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }
        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($plan->bandwidth);

            $time = Carbon::createFromTimestampMs($clientData['expiryTime'])
                ->timezone('Asia/Tehran')->format('Y-m-d H:i:s');
            $expire = Carbon::parse($time)->addDays((int)$plan->days);

            $expiryTimestamp = $expire->timestamp * 1000;

            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $clientData['total'] + $band,
            ];

            $result = updateClient($result);

            if ($result['success']) {


                $order->expire_at = $expire;
                $order->status = 1;
                $order->reminded = 0;
                $order->save();

                $caption = "تمدید سرویس با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');


                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>تمدید تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {

                    $this->deleteChat();
                    $caption = "✅ <b>تمدید آی پی</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }


                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }

            } else {
                $targetUser->balance = $payment->price + $targetUser->balance;
                $targetUser->save();

                $caption = "❌ <b>تمدید سرویس ناموفق بود</b>

متاسفانه عملیات تمدید سرویس شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ تمدید سرویس ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }
        }
    }

    protected function clientBuyExtra($data)
    {
        $order = Orders::find($data['id']);
        $panel = Panels::find($order->panel_id);

        $service = Service::find($panel->panel_type);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $text = headTitle("خرید حجم اضافه");
        $text .= "
💡 لطفاً یکی از گزینه زیر را انتخاب کنید:";


        $allowSellExtra = Setting::where('key', 'extra')->first();
        if (!is_null($allowSellExtra) && $allowSellExtra->value != 1) {
            return $this->home();
        }

        $list = ExtraBandwidth::where('type', $service->id)->where('status', 1)->paginate(20);
        $perGbPrice = $service->price_per_gb;

        $keyboard = [];
        $row = [];
        if (count($list) > 0) {
            foreach ($list as $item) {
                $name = !is_null($item->name) ? $item->name : 'بدون نام';
                $price = calculateExtraDiscount($item, $perGbPrice);
                $price = number_format($price['price']);
                $row[] = [
                    'text' => "{$name} GB | {$price} تومان",
                    'callback_data' => "type=clientSubmitExtra|o_id={$order->id}|ex_id=$item->id",
                ];
                if (count($row) === 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }


        $keyboard[] = $this->clientFooterButtons("type=clientSingleOrder|id={$order->id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->deleteChat();
        $this->method = 'toUser';
        return $this->sendMessage($data, 'message');
    }

    protected function clientSubmitExtra($data)
    {
        $orderId = $data['o_id'];
        $extraId = $data['ex_id'];
        $user = $this->user;

        $extra = ExtraBandwidth::find($extraId);
        $service = Service::find($extra->type);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }
        $perGbPrice = $service->price_per_gb;

        $price = number_format($extra->name * $perGbPrice);

        $detail['extra-id'] = $extra->id;

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = $orderId;
        $payment->price = $extra->name * $perGbPrice;
        $payment->status = 0;
        $payment->detail = $detail;
        $payment->type = 3;
        $payment->expired_at = Carbon::now()->addMinutes(10);
        $payment->save();

        $text = headTitle("💳 انتخاب روش پرداخت");

        $text .= "

🛒 <b>خلاصه سفارش شما</b>

📦 <b>نوع سرویس:</b>
خرید حجم

🌐 <b>حجم انتخابی:</b>
<code>{$extra->name} گیگابایت</code>

💰 <b>مبلغ قابل پرداخت:</b>
<code>{$price}</code>

━━━━━━━━━━━━━━━━━━

🔻 لطفاً روش پرداخت مورد نظر خود را انتخاب کنید:";

        $keyboard[] = [
            [
                'text' => 'کیف پول',
                'callback_data' => "type=paymentWallet|id={$payment->id}",
            ],
        ];

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $keyboard[] = [
                [
                    'text' => 'کارت به کارت',
                    'callback_data' => "type=paymentCartBeCart|id={$payment->id}",
                ],
            ];
        }

        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientRenewOrder|id={$orderId}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientFinalExtra($data)
    {

        $payment = Payment::find($data['id']);
        $targetUser = User::find($payment->user_id);

        $adminMethod = 'toUser';
        $userMethod = 'toUser';

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }
        if ($payment->method == 'cart-be-cart') {
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال تحویل سفارش به کاربر هستیم...\nلطفاً چند لحظه صبر کنید.";
            $adminMethod = 'edit';

            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
        if ($payment->method == 'wallet') {

            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال پردازش سفارش هستیم...\nلطفاً چند لحظه صبر کنید.";

            $this->sendMessage([
                'chat_id' => $targetUser->tel_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');

            $userMethod = 'edit';
        }

        $order = Orders::find($payment->order_id);

        $extra = ExtraBandwidth::find($payment->detail['extra-id']);
        $panel = Panels::find($order->panel_id);

        return $this->ExtraClient($panel, $order, $extra, $targetUser, $payment, $adminMethod, $channelId);
    }

    protected function ExtraClient($panel, $order, $extra, $targetUser, $payment, $adminMethod, $channelId)
    {

        if ($panel->system_type == 'pasarguard') {
            $pasarGuard = new PasarGuard([
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ]);
            if (!$pasarGuard->checkConnection()) {
                return [
                    'status' => false,
                    'message' => $pasarGuard->getLoginStatus()['message'],
                ];
            }
            $result = $pasarGuard->getUserById($order->uid);

            $expire = Carbon::parse($result['expire'])->format('Y-m-d H:i:s');
            $band = gbToByte($extra->name);
            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => $result['data_limit'] + $band,
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);

            if ($result['status'] != false) {
                $caption = "خرید حجم سرویس با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');


                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>خرید حجم تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {

                    $this->deleteChat();
                    $caption = "✅ <b>خرید حجم</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }

                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }
            } else {

                $targetUser->balance = $payment->price + $targetUser->balance;
                $targetUser->save();

                $caption = "❌ <b>خرید حجم ناموفق بود</b>

متاسفانه عملیات خرید حجم شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ خرید حجم ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }


        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($extra->name);


            $expiryTimestamp = $clientData['expiryTime'];

            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $clientData['total'] + $band,
            ];

            $result = updateClient($result);

            if ($result['success']) {

                $caption = "خرید حجم سرویس با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');

                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>خرید حجم تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {

                    $this->deleteChat();
                    $caption = "✅ <b>خرید حجم</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }


                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }

            } else {
                $targetUser->balance = $payment->price + $targetUser->balance;
                $targetUser->save();

                $caption = "❌ <b>خرید حجم ناموفق بود</b>

متاسفانه عملیات خرید حجم شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ خرید حجم ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }
        }

    }


    protected function profile()
    {
        $user = $this->user;
        $balance = number_format($user->balance);
        $text = headTitle("حساب کاربری");
        $text .= "🆔 آیدی تلگرام: `{$user->tel_id}`";
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '💰 موجودی', 'callback_data' => 'ignore'],
                        ['text' => "{$balance}", 'callback_data' => 'ignore'],
                    ],
                    [
                        ['text' => '⬅️ برگشت', 'callback_data' => 'type=home'],
                    ]
                ]
            ])
        ];
        $this->method = 'edit';
        return $this->sendMessage($data, 'message');
    }

    /**
     * Client Area
     */


    /**
     * Admin Area
     */
    protected function adminMenu($type)
    {
        $buttons = [];

        $buttons[] = [
            [
                'text' => "👥 لیست کاربران",
                'callback_data' => 'type=adminUserList'],
        ];

//        $buttons[] = [
//            ['text' => "🛒 سفارشات اخیر",
//                'callback_data' => 'type=adminOrdersList'],
//            [
//                'text' => "💳 تراکنشات اخیر",
//                'callback_data' => 'type=recent-transactions'],
//        ];

        $buttons[] = [
            [
                'text' => "🖥 پنل‌ها",
                'callback_data' => 'type=adminPanelMenu',
            ],
        ];

        $buttons[] = [
            [
                'text' => "📦 تعرفه ها",
                'callback_data' => 'type=adminPlans',
            ],
            [
                'text' => "📥 دریافت بکاپ",
                'callback_data' => 'type=backup',
            ],
        ];

        $buttons[] = [
            ['text' => "🤖 پیام‌های ربات",
                'callback_data' => 'type=bot-messages'],
            ['text' => "📨 ارسال پیام",
                'callback_data' => 'type=send-message'],
        ];
        $buttons[] = [
            [
                'text' => "تنظمیات کلی",
                'callback_data' => 'type=adminSetting',
            ],
        ];

        $buttons[] = [
            [
                'text' => "🏠 پنل کاربر",
                'style' => 'success',
                'callback_data' => 'type=home',
            ],
        ];

        $text = headTitle("👑 پنل مدیریت ربات");
        $text .= "
⚙️ مدیریت کاربران، سفارشات، سرویس‌ها،
پنل‌ها، تراکنش‌ها و تنظیمات سیستم

📌 لطفاً یکی از گزینه‌های زیر را انتخاب کنید.
";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    // Users List
    protected function adminUserList($type)
    {
        $page = $type['page'] ?? 1;
        $filter = $type['filter'] ?? null;
        $search = $type['search'] ?? null;

        $query = User::query();

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        switch ($filter) {

            case 'admins':
                $query->where('is_admin', 1);
                break;

            case 'resellers':
                $query->where('is_seller', 1);
                break;

            case 'normal':
                $query->where('is_admin', 0)
                    ->where('is_seller', 0);
                break;
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($search)) {

            $query->where(function ($q) use ($search) {

                $q->where('tel_id', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%")
                    ->orWhere('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%");
            });
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("👥مدیریت کاربران");
        $text .= "
🔎 جستجو بر اساس:
• آیدی تلگرام
• نام کاربری
• نام و نام خانوادگی

📌 برای مشاهده جزئیات،
روی کاربر موردنظر کلیک کنید.
";

        if ($filter) {
            $text .= "📌 فیلتر فعال: <code>{$filter}</code>\n\n";
        }

        $keyboard = [];
        $row = [];

        /*
        |--------------------------------------------------------------------------
        | User Buttons
        |--------------------------------------------------------------------------
        */

        foreach ($users as $user) {

            $name = trim(
                ($user->first_name ?? '') . ' ' .
                ($user->last_name ?? '')
            );


            $username = !empty($user->username)
                ? "@{$user->username}"
                : 'بدون یوزرنیم';

            $btnText = !empty($name) ? "{$name}" : "{$username}";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminUserDetail|id={$user->id}"
            ];

            // دو ستونه
            if (count($row) == 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        // باقی‌مانده
        if (!empty($row)) {
            $keyboard[] = $row;
        }


        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = [];

        if ($users->currentPage() > 1) {

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'type=adminUserList|page=' . ($page - 1)
            ];
        } else {
            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'ignore',
                'style' => 'danger'
            ];
        }

// شماره صفحه وسط
        $pagination[] = [
            'text' => "📄 {$users->currentPage()} / {$users->lastPage()}",
            'callback_data' => 'ignore',
            'style' => 'success'
        ];

        if ($users->hasMorePages()) {

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'type=adminUserList|page=' . ($page + 1)
            ];
        } else {
            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'ignore',
                'style' => 'danger'
            ];
        }

        $keyboard[] = $pagination;

        /*
        |--------------------------------------------------------------------------
        | Filter Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = [
            [
                'text' => 'جستجو',
                'callback_data' => 'type=adminUserSearch',
                'style' => 'primary'
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Home Button
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=admin-home');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSearch($type)
    {
        $text = headTitle("👥جستجو کاربران");
        $text .= "
🔎 جستجو بر اساس:
• آیدی تلگرام
• نام کاربری
• نام و نام خانوادگی

📌 برای مشاهده جزئیات،
روی کاربر موردنظر کلیک کنید.
";

        $keyboard[] = $this->adminFooterButtons('type=adminUserList');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->updatePath('adminUserList');

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserDetail($type = null)
    {
        $id = $type['id'];

        $currentUser = $this->user;

        $user = User::find($id);

        if (is_null($user)) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | User Info
        |--------------------------------------------------------------------------
        */

        $fullName = trim(
            ($user->first_name ?? '') . ' ' .
            ($user->last_name ?? '')
        );

        if (empty($fullName)) {
            $fullName = '—';
        }

        $username = !empty($user->username)
            ? '@' . $user->username
            : '—';

        $balance = number_format((float)$user->balance);

        $status = match ((int)$user->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 محدود',
        };

        $isAdmin = ((int)$user->is_admin === 1)
            ? '✅'
            : '❌';

        $isSeller = ((int)$user->is_seller === 1)
            ? '✅'
            : '❌';

//        $lastOnline = !empty($user->updated_at)
//            ? jdate($user->updated_at)->ago()
//            : '—';

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("👤پروفایل کاربر");

        $text .= "🪪 <b>نام کامل:</b>\n";
        $text .= "<code>{$fullName}</code>\n\n";

        $text .= "👤 <b>نام کاربری:</b>\n";
        $text .= "<code>{$username}</code>\n\n";

        $text .= "💰 <b>موجودی کیف پول:</b>\n";
        $text .= "<code>{$balance}</code> تومان\n\n";

        $text .= "📡 <b>وضعیت حساب:</b>\n";
        $text .= "{$status}\n\n";

        $text .= "🆔 <b>تلگرام آیدی:</b>\n";
        $text .= "<code>{$user->tel_id}</code>\n\n";

//        $text .= "⏳ <b>آخرین فعالیت:</b>\n";
//        $text .= "<code>{$lastOnline}</code>\n\n";

        $text .= "👮 <b>دسترسی ادمین:</b> {$isAdmin}\n";
        $text .= "💼 <b>وضعیت فروشنده:</b> {$isSeller}\n";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [
            [
                [
                    'text' => '💰 ویرایش موجودی',
                    'callback_data' => "type=adminUserBalance|id={$user->id}"
                ],
                [
                    'text' => '🛒 سفارشات کاربر',
                    'callback_data' => "type=adminOrdersList|userId={$user->id}"
                ]
            ],

            [
                [
                    'text' => '⚙️ تنظیمات کاربر',
                    'callback_data' => "type=adminUserSettings|id={$user->id}"
                ],
                [
                    'text' => '💳 تراکنش‌ها',
                    'callback_data' => "type=adminUserTransactions|id={$user->id}"
                ]
            ],

            [
                [
                    'text' => '✉️ ارسال پیام',
                    'callback_data' => "type=adminUserSendMessage|id={$user->id}"
                ],
                [
                    'text' => ((int)$user->status === 1)
                        ? '🔴 غیرفعال کردن'
                        : '🟢 فعال کردن',

                    'callback_data' => "type=adminToggleUserStatus|id={$user->id}"
                ]
            ],
            [
                [
                    'text' => '⬅️ بازگشت',
                    'callback_data' => 'type=adminUserList'
                ]
            ],

            [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserBalance($type)
    {
        $id = $type['id'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        $username = $user->username ? "@{$user->username}" : '—';
        $balance = number_format($user->balance);
        $text = headTitle("💰 مدیریت موجودی کیف پول");

        $text .= "👤 کاربر:\n<code>{$username}</code>\n\n";
        $text .= "⚙️ لطفا یکی از گزینه‌های زیر را انتخاب کنید:\n موجودی کیف پول{$balance}";

        $keyboard[] =
            [
                [
                    'text' => '➕ شارژ کیف پول',
                    'callback_data' => "type=adminUserBalanceAction|id={$id}|value=increment",
                    'style' => 'success'
                ],
                [
                    'text' => '➖ کسر از کیف پول',
                    'callback_data' => "type=adminUserBalanceAction|id={$id}|value=decrement",
                    'style' => 'danger'
                ],
            ];
        $keyboard[] = $this->adminFooterButtons("type=adminUserDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserBalanceAction($type = null)
    {
        $action = $type['value'];
        $id = $type['id'];

        $user = $this->user;

        $this->updatePath('adminUserBalanceActionBalance');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['user-action'] = $action;
        $telDetail['user-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        if ($action == 'increment') {
            $text = headTitle("💰 افزایش موجودی کاربر");

            $text .= "✏️ لطفا مبلغی که می‌خواهید\n";
            $text .= "به کیف پول کاربر اضافه شود را وارد کنید.";
        } else {
            $text = headTitle("💰 کاهش موجودی کاربر");
            $text .= "✏️ لطفا مبلغی که می‌خواهید\n";
            $text .= "از کیف پول کاربر کسر شود را وارد کنید.";
        }

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [
            [
                [
                    'text' => '📂 انصراف',
                    'style' => 'danger',
                    'callback_data' => "type=adminUserDetail|id={$id}"
                ],
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserBalanceActionBalance()
    {
        $balance = (float)$this->text;

        $user = $this->user;

        $action = $user->tel_detail['user-action'];
        $id = $user->tel_detail['user-id'];

        $targetUser = User::find($id);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        if ($action == 'increment') {

            $targetUser->increment('balance', $balance);
            $operationText = '➕ افزایش موجودی';

        } else {

            // جلوگیری از منفی شدن موجودی
            if ($targetUser->balance < $balance) {

                return $this->sendTemporaryMessage(
                    '❌ موجودی کاربر کافی نیست و نمی‌تواند منفی شود'
                );
            }

            $targetUser->decrement('balance', $balance);
            $operationText = '➖ کاهش موجودی';
        }

        // رفرش دیتا
        $targetUser->refresh();

        $text = headTitle("💰تراکنش موجودی کاربر");


        $text .= "⚙️ <b>نوع عملیات:</b>\n<code>{$operationText}</code>\n\n";

        $text .= "💵 <b>مقدار تغییر:</b>\n<code>{$balance}</code>\n\n";

        $text .= "💰 <b>موجودی جدید کاربر:</b>\n<code>{$targetUser->balance}</code>\n\n";

        $text .= "✅ <b>وضعیت:</b> با موفقیت انجام شد";

        $keyboard = [
            [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ],
                [
                    'text' => '👤 جزئیات کاربر',
                    'callback_data' => "type=adminUserDetail|id={$id}"
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSettings($type)
    {
        $id = $type['id'];

        $targetUser = User::find($id);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        $username = $targetUser->username ? "@{$targetUser->username}" : '—';

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $isAdmin = (int)$targetUser->is_admin === 1;
        $isSeller = (int)$targetUser->is_seller === 1;

        $keyboard = [

            [
                [
                    'text' => '👮 دسترسی ادمین',
                    'callback_data' => 'ignore',
                    'style' => 'primary'
                ]
            ],

            [
                [
                    'text' => $isAdmin ? 'فعال ✅' : 'فعال',
                    'callback_data' => "type=adminUserIsAdmin|id={$id}|value=1",
                ],
                [
                    'text' => !$isAdmin ? 'غیرفعال ❌' : 'غیرفعال',
                    'callback_data' => "type=adminUserIsAdmin|id={$id}|value=0",
                ],
            ],

            [
                [
                    'text' => '💼 دسترسی فروشنده',
                    'callback_data' => 'ignore',
                    'style' => 'primary'
                ]
            ],

            [
                [
                    'text' => $isSeller ? 'فعال ✅' : 'فعال',
                    'callback_data' => "type=adminUserIsSeller|id={$id}|value=1",
                ],
                [
                    'text' => !$isSeller ? 'غیرفعال ❌' : 'غیرفعال',
                    'callback_data' => "type=adminUserIsSeller|id={$id}|value=0",
                ],
            ],

            [
                [
                    'text' => '🎁 درصد تخفیف فروشنده',
                    'callback_data' => "type=adminUserSellerDiscount|id={$id}",
                    'style' => 'primary'
                ],
                [
                    'text' => '🎁 اینبوند های فروشنده',
                    'callback_data' => "type=adminUserSellerInbounds|id={$id}",
                    'style' => 'primary'
                ]
            ],

            [
                [
                    'text' => '📂 انصراف',
                    'callback_data' => "type=adminUserDetail|id={$id}",
                    'style' => 'danger'
                ]
            ],

            [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle(" ⚙️ تنظیمات کاربر");


        $text .= "👤 کاربر:\n<code>{$username}</code>\n\n";
        $text .= "ℹ️ راهنما:\n✅ فعال = روشن بودن دسترسی\n❌ غیرفعال = خاموش بودن دسترسی\n\n برای تغییر دسترسی بر روی گزینه مورد نظر گلیگ کنید\n\n";
        $text .= "⚙️ یکی از گزینه‌ها را انتخاب کنید:";

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserIsAdmin($type)
    {
        $id = $type['id'];
        $value = $type['value'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        if ($user->id == env('SUPERADMIN')) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر درسترسی این کاربر رو ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        if ($user->id == $this->user->id) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر دسترسی خود را ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        $user->is_admin = $value;
        $user->save();

        $statusText = $value ? '🟢 فعال شد' : '🔴 غیرفعال شد';

        $text = headTitle("🔔 بروزرسانی دسترسی ادمین");

        $text .= "وضعیت دسترسی شما تغییر کرد:\n";
        $text .= "<b>{$statusText}</b>\n\n";

        $text .= "در صورت نیاز با پشتیبانی تماس بگیرید.";

        $data = [
            'chat_id' => $user->tel_id,
            'text' => trim($text),
            'parse_mode' => 'HTML',
        ];

        $this->method = 'toUser';
        $this->sendMessage($data, 'message');

        $this->method = 'edit';
        $this->adminUserSettings($type);

        $data = [
            'callback_query_id' => $this->callbackId,
            'text' => 'وضعیت کاربر به عنوان مدیر تغییر پیدا کرد',
            'show_alert' => true,
            'cache_time' => 1,
        ];
        return $this->telegramSdk->answerCallback($data);


    }

    protected function adminUserIsSeller($type)
    {
        $id = $type['id'];
        $value = $type['value'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        if ($user->id == env('SUPERADMIN')) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر درسترسی این کاربر رو ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        if ($user->id == $this->user->id) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر دسترسی خود را ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        $user->is_seller = $value;
        $user->save();

        if ($value) {
            $inbounds = Inbounds::where('status', 1)->get();
            foreach ($inbounds as $inbound) {
                $newSellerInbound = SellerInbound::where('user_id', $user->id)->where('inbound_id', $inbound->id)->first();
                if (is_null($newSellerInbound)) {
                    $sellerInbound = new SellerInbound();
                    $sellerInbound->user_id = $user->id;
                    $sellerInbound->inbound_id = $inbound->id;
                    $sellerInbound->status = $inbound->status;
                    $sellerInbound->save();
                }
            }
        }

        $statusText = $value ? '🟢 فعال شد' : '🔴 غیرفعال شد';

        $text = "🔔 <b>بروزرسانی دسترسی فروشنده</b>\n\n";

        $text .= "وضعیت دسترسی شما تغییر کرد:\n";
        $text .= "<b>{$statusText}</b>\n\n";

        $text .= "در صورت نیاز می‌توانید از پشتیبانی اطلاعات بیشتری دریافت کنید.";

        $data = [
            'chat_id' => $user->tel_id,
            'text' => trim($text),
            'parse_mode' => 'HTML',
        ];

        $this->method = 'toUser';
        $this->sendMessage($data, 'message');

        $this->method = 'edit';
        $this->adminUserSettings($type);

        $data = [
            'callback_query_id' => $this->callbackId,
            'text' => 'وضعیت کاربر به عنوان فروشنده با موفقیت تغییر پیدا کرد',
            'show_alert' => true,
            'cache_time' => 1,
        ];


        return $this->telegramSdk->answerCallback($data);

    }

    protected function adminUserSellerDiscount($type)
    {
        $id = $type['id'];

        $user = $this->user;

        $this->updatePath('adminUserSellerDiscountAmount');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['user-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $discount = $telDetail['discount'] ?? 0;

        $text = headTitle("🎯 درصد تخفیف فروشنده");
        $text .= "💰 تخفیف فعلی: <code>{$discount}%</code>\n\n";

        $text .= "✏️ لطفا درصد تخفیفی که می‌خواهید\n";
        $text .= "برای این کاربر به عنوان فروشنده اعمال شود را وارد کنید.\n\n";

        $text .= "⚠️ این تخفیف روی موارد زیر اعمال می‌شود:\n";
        $text .= "• خرید سرویس\n";
        $text .= "• تمدید سرویس\n";
        $text .= "• خرید حجم\n";

        $keyboard = [
            [
                [
                    'text' => '📂 انصراف',
                    'style' => 'danger',
                    'callback_data' => "type=adminUserSettings|id={$id}"
                ],
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminUserSellerDiscountAmount()
    {
        $validate = Validator::make([
            'discount' => $this->text
        ], [
            'discount' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'discount.required' => '❌ لطفا مقدار تخفیف را وارد کنید.',
            'discount.numeric' => '❌ مقدار باید عددی باشد.',
            'discount.min' => '❌ حداقل مقدار 0 است.',
            'discount.max' => '❌ حداکثر مقدار 100 است.',
        ]);

        if ($validate->fails()) {
            return $this->sendTemporaryMessage($validate->errors()->first());
        }

        $user = $this->user;
        $telDetail = $user->tel_detail ?? [];

        $userId = $telDetail['user-id'] ?? null;

        $discount = (float)$this->text;


        if (!$userId) {
            return $this->sendTemporaryMessage('کاربر انتخاب نشده است');
        }

        $targetUser = User::find($userId);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Save discount in tel_detail (correct way)
        |--------------------------------------------------------------------------
        */

        $targetDetail = $targetUser->tel_detail ?? [];
        $targetDetail['discount'] = $discount;

        $targetUser->tel_detail = $targetDetail;
        $targetUser->save();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        $text = headTitle(" 🎯 تخفیف فروشنده بروزرسانی شد");
        $text .= "💰 مقدار تخفیف:\n<code>{$discount}%</code>\n\n";
        $text .= "✅ با موفقیت ذخیره شد";

        $keyboard = [
            [
                [
                    'text' => '⚙️ تنظیمات کاربر',
                    'callback_data' => "type=adminUserSettings|id={$userId}"
                ],
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];


        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSellerInbounds($type)
    {
        $id = $type['id'];
        $page = $type['page'] ?? 1;

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Seller Inbounds (NEW STRUCTURE)
        |--------------------------------------------------------------------------
        */

        $selectedInboundIds = SellerInbound::where('user_id', $user->id)
            ->pluck('inbound_id')
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | Inbounds Pagination
        |--------------------------------------------------------------------------
        */

        $inbounds = Inbounds::with(['panel'])
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $username = $user->username ? "@{$user->username}" : '—';

        $text = headTitle("🌐 اینباندهای فروشنده");

        $text .= "👤 کاربر:\n<code>{$username}</code>\n\n";

        $text .= "ℹ️ راهنما:\n";
        $text .= "✅ فعال\n❌ غیرفعال\n\n";

        $text .= "⚙️ برای تغییر وضعیت روی هر اینباند کلیک کنید.";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        foreach ($inbounds as $inbound) {

            $isActive = in_array($inbound->id, $selectedInboundIds);

            $icon = $isActive ? '✅' : '❌';

            $panelName = $inbound->panel->name ?? 'بدون پنل';

            $btnText = "{$icon} {$panelName} | {$inbound->remark} | {$inbound->port}";

            $keyboard[] = [
                [
                    'text' => $btnText,
                    'callback_data' => "type=adminUserSellerChangeInbound|id={$id}|inbound_id={$inbound->id}",
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = [];

        if ($inbounds->currentPage() > 1) {

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'type=adminUserSellerInbounds'
                    . "|id={$id}|page=" . ($page - 1)
            ];
        }

        $pagination[] = [
            'text' => "📄 {$inbounds->currentPage()} / {$inbounds->lastPage()}",
            'callback_data' => 'ignore',
            'style' => 'primary'
        ];

        if ($inbounds->hasMorePages()) {

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'type=adminUserSellerInbounds'
                    . "|id={$id}|page=" . ($page + 1)
            ];
        }

        $keyboard[] = $pagination;

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '📂 بازگشت',
                'callback_data' => "type=adminUserSettings|id={$id}"
            ]
        ];

        $keyboard[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSellerChangeInbound($type)
    {
        $id = $type['id'];
        $inbound_id = (int)$type['inbound_id'];

        $seller = Seller::where('user_id', $id)->first();

        if (!$seller) {
            return $this->sendTemporaryMessage('تنظیمات فروشنده پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Check existing relation
        |--------------------------------------------------------------------------
        */

        $exists = SellerInbound::where('user_id', $id)
            ->where('inbound_id', $inbound_id)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Toggle
        |--------------------------------------------------------------------------
        */

        if ($exists) {

            $exists->delete();

            $message = '❌ اینباند غیرفعال شد';

        } else {

            SellerInbound::create([
                'user_id' => $id,
                'inbound_id' => $inbound_id
            ]);

            $message = '✅ اینباند فعال شد';
        }

        /*
        |--------------------------------------------------------------------------
        | Alert (non-blocking)
        |--------------------------------------------------------------------------
        */

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => $message,
            'show_alert' => false,
            'cache_time' => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reload UI
        |--------------------------------------------------------------------------
        */

        return $this->adminUserSellerInbounds([
            'id' => $id
        ]);
    }

    protected function adminToggleUserStatus($type)
    {
        $id = $type['id'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Toggle Status
        |--------------------------------------------------------------------------
        */

        if ((int)$user->status === 1) {

            $user->status = -1;

            $statusText = '🔴 غیرفعال شد';
            $style = 'danger';

        } else {

            $user->status = 1;

            $statusText = '🟢 فعال شد';
            $style = 'success';
        }

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Notify User
        |--------------------------------------------------------------------------
        */

        $notifyText = "🔔 <b>بروزرسانی وضعیت حساب</b>\n\n";
        $notifyText .= "وضعیت حساب شما تغییر کرد:\n";
        $notifyText .= "<b>{$statusText}</b>";

        $notifyData = [
            'chat_id' => $user->tel_id,
            'text' => trim($notifyText),
            'parse_mode' => 'HTML',
        ];

        $this->method = 'toUser';
        $this->sendMessage($notifyData, 'message');

        /*
        |--------------------------------------------------------------------------
        | Admin Alert
        |--------------------------------------------------------------------------
        */

        $data = [
            'callback_query_id' => $this->callbackId,
            'text' => "وضعیت کاربر {$statusText}",
            'show_alert' => true,
            'cache_time' => 1,
        ];

        $this->telegramSdk->answerCallback($data);

        /*
        |--------------------------------------------------------------------------
        | Reload User Detail
        |--------------------------------------------------------------------------
        */
        $this->method = 'edit';
        return $this->adminUserDetail([
            'id' => $id
        ]);
    }


    // Start Panels

    protected function adminPanelMenu($type = null)
    {
        $buttons = [];

        $buttons[] = [
            [
                'text' => "🧩 انواع سرویس‌ها",
                'callback_data' => 'type=adminService',
            ],
            [
                'text' => "🌍 کشورها",
                'callback_data' => 'type=adminCountries',
            ],
        ];

        $buttons[] = [
            [
                'text' => "📦 تعرفه‌ها",
                'callback_data' => 'type=adminPlans',
            ],
            [
                'text' => "🖥 پنل‌ها",
                'callback_data' => 'type=admin-panels',
            ],
        ];

        $buttons[] = [
            [
                'text' => "💾 حجم‌های اضافه",
                'callback_data' => 'type=adminExtraBandwidths',
            ],
            [
                'text' => "🌐 اینباندها",
                'callback_data' => 'type=adminInbounds',
            ],
        ];

        $buttons[] = [
            [
                'text' => "📘 راهنما و آموزش",
                'callback_data' => 'type=adminServiceHelp',
                'style' => 'primary'
            ],
        ];

        $buttons[] = $this->adminFooterButtons();

        $text = headTitle(" ⚙️ مدیریت سرویس‌ها");
        $text .= "از این بخش می‌توانید:\n\n";

        $text .= "🌍 کشورها را مدیریت کنید\n";
        $text .= "🖥 پنل‌ها را تنظیم کنید\n";
        $text .= "📦 تعرفه های فروش را مدیریت کنید\n";
        $text .= "💾 حجم‌های اضافه را تنظیم کنید\n";
        $text .= "🌐 اینباندها را مدیریت کنید\n\n";

        $text .= "👇 لطفاً یکی از گزینه‌های زیر را انتخاب کنید.";

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPanels($type)
    {
        $page = $type['page'] ?? 1;

        $list = Panels::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = ['inline_keyboard' => []];

        foreach ($list as $panel) {
            $status = match ((int)$panel->status) {
                1 => '🟢 فعال',
                0 => '🟡 در انتظار',
                -1 => '🔴 غیرفعال',
                default => '⚪ نامشخص',
            };
            $name = !is_null($panel->name) ? $panel->name : 'بدون نام';
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "نام: {$name} | {$status}",
                    'callback_data' => "type=adminPanelDetail|id={$panel->id}",
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminPanels');

        if (!empty($pagination)) {
            $keyboard['inline_keyboard'][] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Actions
        |--------------------------------------------------------------------------
        */

        $keyboard['inline_keyboard'][] = [
            [
                'text' => '➕ ایجاد پنل جدید',
                'callback_data' => 'type=adminCreatePanel',
                'style' => 'success'
            ]
        ];

        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminPanelMenu");

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("🖥 مدیریت پنل‌ها");

        $text .= "📌 وضعیت پنل‌ها:\n";
        $text .= "🟢 فعال = پنل در حال کار و قابل استفاده\n";
        $text .= "🟡 در انتظار = پنل ثبت شده ولی هنوز فعال نشده\n";
        $text .= "🔴 غیرفعال = پنل خاموش یا غیرقابل استفاده\n\n";

        $text .= "📍 برای مشاهده جزئیات یا ویرایش، روی هر پنل کلیک کنید.\n\n";

        $text .= "📄 صفحه: {$list->currentPage()} از {$list->lastPage()}\n";
        $text .= "📦 تعداد کل: {$list->total()} پنل";

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');
    }

    protected function adminPanelDetail($type)
    {
        $id = $type['id'];

        $panel = Panels::find($id);

        if (is_null($panel)) {
            return $this->sendTemporaryMessage('panel not found please try again');
        }

        $fields = [
            'name' => ['label' => 'نام', 'value' => $panel->name],
            'url' => ['label' => 'آدرس', 'value' => $panel->url],
            'username' => ['label' => 'نام کاربری', 'value' => $panel->username],
            'password' => ['label' => 'رمز عبور', 'value' => $panel->password],
            'panel_type' => ['label' => 'نوع پنل', 'value' => $panel->panel_type],
            'sub_address' => ['label' => 'لینک ساب', 'value' => $panel->sub_address],
            'system_type' => ['label' => 'سیستم', 'value' => $panel->system_type],
            'status' => ['label' => 'وضعیت', 'value' => $panel->status],
        ];
        if ($panel->system_type == 'sanaie') {
            $fields['country_id'] = ['label' => 'کشور', 'value' => $panel->country_id];
        }

        $text = headTitle("📋 جزئیات پنل");

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            if ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => 'فعال',
                    -1 => 'غیرفعال',
                    default => 'معلق',
                };

            } elseif ($key == 'country_id') {
                $valueText = Countries::find($field['value'])->name ?? '—';

            } elseif ($key == 'panel_type') {
                $valueText = Service::find($field['value'])->name ?? '—';

            } else {

                $valueText = $isEmpty
                    ? '—'
                    : (string)$field['value'];

                // 🔐 اسپویل برای فیلدهای حساس
                if (in_array($key, ['username', 'password', 'url'])) {
                    $valueText = "<tg-spoiler>{$valueText}</tg-spoiler>";
                } else {
                    $valueText = htmlspecialchars($valueText);
                }
            }

            if (in_array($key, ['username', 'password', 'url'])) {
                $text .= "▪️ <b>{$field['label']}</b>: {$valueText}\n";
            } else {
                $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            }


            // دکمه‌ها
            $textBtn = $isEmpty
                ? "🔴 " . $field['label']
                : "🟢 " . $field['label'];

            $row[] = [
                'text' => $textBtn,
                'style' => $isEmpty ? 'danger' : '',
                'callback_data' => "type=adminEditPanel|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        if ($panel->system_type == 'pasarguard') {
            $keyboard[] = [
                [
                    'text' => '📂 بررسی اتصال',
                    'callback_data' => "type=adminConnectPanel|id=$panel->id"
                ], [
                    'text' => '📂دریافت گروه ها',
                    'callback_data' => "type=adminGetInbounds|id=$panel->id"
                ]
            ];

            $keyboard[] = [
                [
                    'text' => 'لیست گروه ها',
                    'callback_data' => "type=adminPasarGuardGroups|id=$panel->id",
                ]
            ];
        } else {
            $keyboard[] = [
                [
                    'text' => '📂 بررسی اتصال',
                    'callback_data' => "type=adminConnectPanel|id=$panel->id"
                ], [
                    'text' => '📂دریافت اینبوند ها',
                    'callback_data' => "type=adminGetInbounds|id=$panel->id"
                ]
            ];
        }

        $keyboard[] = [
            [
                'text' => 'حذف پنل',
                'callback_data' => "type=adminPanelDeleteDetail|id=$panel->id",
                'style' => 'danger'
            ]
        ];

        $text .= "\n⚠️ فیلدهای خالی با مقدار — مشخص شده‌اند.";


        $keyboard[] = $this->adminFooterButtons('type=admin-panels');


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCreatePanel($type)
    {
        $user = $this->user;

        $newPanel = new Panels();
        $newPanel->admin_id = $user->id;
        $newPanel->status = -1;
        $newPanel->save();

        $type['id'] = $newPanel->id;
        return $this->adminPanelDetail($type);
    }

    protected function adminEditPanel($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminUpdatePanel');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['panel-key'] = $key;
        $telDetail['panel-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $fields = [
            'name' => ['label' => 'نام'],
            'url' => ['label' => 'آدرس'],
            'username' => ['label' => 'نام کاربری'],
            'country_id' => ['label' => 'کشور'],
            'password' => ['label' => 'رمز عبور'],
            'panel_type' => ['label' => 'نوع پنل'],
            'system_type' => ['label' => 'سیستم'],
            'sub_address' => ['label' => 'لینک ساب',],
            'status' => ['label' => 'وضعیت'],

        ];

        $panel = Panels::find($id);

        $oldValue = $panel->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";

        $customFiles = ['system_type', 'type', 'panel_type', 'status', 'country_id'];
        if (in_array($key, $customFiles)) {

            if ($key == 'system_type') {
                $keyboard[] = [
                    [
                        'text' => 'X-Sanaie',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=sanaie",
                    ],
                    [
                        'text' => 'PasarGuard',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=pasarguard",
                    ]
                ];
            } elseif ($key == 'panel_type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }

            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=1",
                    ],
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=-1",
                    ],
                ];
            } elseif ($key == 'country_id') {
                $country = Countries::get();
                foreach ($country as $item) {

                    $isSelected = ((int)$panel->country_id === (int)$item->id);

                    if ($isSelected) {
                        $oldValue = $item->name;
                    }
                    $keyboard[] = [
                        [
                            'text' => $item->name . ($isSelected ? ' ✅' : ''),
                            'callback_data' => "type=adminUpdatePanel|id={$id}|value={$item->id}",
                            'style' => $isSelected ? 'success' : ''
                        ]
                    ];
                }
            }
        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code>";


        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminUpdatePanel($type = null)
    {
        $user = $this->user;
        $key = $user->tel_detail['panel-key'];
        $id = $user->tel_detail['panel-id'];

        $fields = [
            'name' => ['label' => 'نام'],
            'url' => ['label' => 'آدرس'],
            'country_id' => ['label' => 'کشور'],
            'username' => ['label' => 'نام کاربری'],
            'password' => ['label' => 'رمز عبور'],
            'panel_type' => ['label' => 'نوع پنل'],
            'system_type' => ['label' => 'سیستم'],
            'status' => ['label' => 'وضعیت'],
            'sub_address' => ['label' => 'لینک ساب',],

        ];


        $customFiles = ['system_type', 'type', 'panel_type', 'status', 'country_id'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            Panels::where('id', $id)->update([$key => $value]);

        } else {
            $text = rtrim($this->text, '/');
            Panels::where('id', $id)->update([$key => $text]);

            if ($key == 'url') {
                $checkSub = Panels::find($id);
                if (is_null($checkSub->sub_address)) {
                    $scheme = parse_url($text, PHP_URL_SCHEME) ?? 'https';
                    $host = parse_url($text, PHP_URL_HOST);
                    $result = $scheme . '://' . $host . ':2096/sub/';
                    $checkSub->sub_address = $result;
                    $checkSub->save();
                }
            }
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminConnectPanel($type)
    {
        $id = $type['id'];

        $panel = Panels::find($id);


        if ($panel->system_type == "pasarguard") {

            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ];

            $pasarGuard = new PasarGuard($data);
            $result = $pasarGuard->checkConnection();

            if (!is_null($result)) {
                $Data = [
                    'Domain' => $result,
                ];
                $panel->detail = $Data;
                $panel->save();
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '✅ اتصال به سرور با موفقیت برقرار شد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            } else {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '❌ اتصال به سرور برقرار نشد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            }
        } else {
            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password
            ];
            $session = loginToSanaie($data);
            if (!empty($session['session'])) {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '✅ اتصال به سرور با موفقیت برقرار شد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            } else {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '❌ اتصال به سرور برقرار نشد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            }

        }

        return $this->telegramSdk->answerCallback($data);
    }

    protected function adminGetInbounds($type)
    {
        $id = $type['id'];

        $panel = Panels::find($id);
        if ($panel->system_type == "pasarguard") {

            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ];
            $pasarGuard = new PasarGuard($data);
            $result = $pasarGuard->getGroups();

            foreach ($result['groups'] as $item) {
                $newInbound = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->first();
                if (is_null($newInbound)) {
                    $newInbound = new Inbounds();
                    $newInbound->status = -1;
                }
                $newInbound->panel_id = $panel->id;
                $newInbound->inbound_id = $item['id'];
                $newInbound->remark = $item['name'];
                $newInbound->save();
            }

            $newInbound = Inbounds::where('panel_id', $panel->id)->pluck('remark')->implode(',');

            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => "✅ گروه ها با موفقیت دریافت شدند.
گروه های دریافت شده: $newInbound",
                'show_alert' => true,
                'cache_time' => 1,
            ];

        } else {
            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password
            ];

            $session = loginToSanaie($data);

            if (!empty($session['session'])) {
                $data = [
                    'session' => $session['session'],
                    'url' => $panel->url
                ];
                $inbounds = getInbounds($data);
                if (!empty($inbounds)) {
                    foreach ($inbounds['inbounds'] as $item) {
                        $newInbound = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->first();
                        if (is_null($newInbound)) {
                            $newInbound = new Inbounds();
                            $newInbound->status = $item['enable'] ? 1 : 0;
                        }
                        $newInbound->panel_id = $panel->id;
                        $newInbound->inbound_id = $item['id'];
                        $newInbound->port = $item['port'];
                        $newInbound->remark = $item['remark'];
                        $newInbound->setting = $item;
                        $newInbound->save();
                    }
                    $newInbound = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->pluck('remark')->implode(',');

                    $data = [
                        'callback_query_id' => $this->callbackId,
                        'text' => "✅ اینبوند ها با موفقیت دریافت شدند.
گروه های دریافت شده: $newInbound",
                        'show_alert' => true,
                        'cache_time' => 1,
                    ];
                } else {
                    $data = [
                        'callback_query_id' => $this->callbackId,
                        'text' => '❌ اینبوندی بر روی سرور یافت نشد',
                        'show_alert' => true,
                        'cache_time' => 1,
                    ];
                }
            } else {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '❌ اتصال به سرور برقرار نشد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            }
        }
        return $this->telegramSdk->answerCallback($data);

    }

    protected function adminPanelDeleteDetail($type)
    {
        $id = $type['id'];

        $service = Panels::find($id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ پنل موردنظر پیدا نشد.');
        }

        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف پنل\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این پنل اطمینان دارید؟\n\n";

        $text .= "📦 نام پنل:\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";

        $text .= "❗ پس از حذف، امکان بازگردانی تعرفه وجود نخواهد داشت.\n\n";

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminPanelDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminPlanDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPanelDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = Panels::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('پنل پیدا نشد');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "پنل با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);


        return $this->adminPanels(['page' => 1]);
    }

    // End Panels


    // Start Plans
    protected function adminPlans($type)
    {
        $page = $type['page'] ?? 1;

        $list = Plans::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $text = "📦 <b>لیست تعرفه ها</b>\n";
        $text .= "📄 صفحه: <code>{$list->currentPage()}</code>\n";
        $text .= "📊 تعداد: <code>{$list->total()}</code>\n\n";

        $keyboard = [];
        $row = [];


        foreach ($list as $plan) {

            // نوع پلن
            $planType = Service::find($plan->type);
            if (!is_null($planType)) {
                $planType = $planType->name;
            } else {
                $planType = '--';
            }

            // وضعیت
            $status = ((int)$plan->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $row[] = [
                'text' => "نام:{$plan->name} | {$planType} | {$status}",
                'callback_data' => "type=adminPlanDetail|id={$plan->id}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        // صفحه‌بندی
        $pagination = $this->paginationFooterButton($list, $page, 'adminPlans');
        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        // ایجاد پلن
        $keyboard[] = [
            [
                'text' => '➕ ایجاد پلن جدید',
                'callback_data' => 'type=adminPlanCreate',
                'style' => 'success'
            ]
        ];

        // برگشت

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminPlanCreate($type)
    {
        $user = $this->user;

        $newPanel = new Plans();
        $newPanel->admin_id = $user->id;
        $newPanel->status = 0;
        $newPanel->save();

        $type['id'] = $newPanel->id;
        return $this->adminPlanDetail($type);
    }

    protected function adminPlanDetail($type)
    {
        $id = $type['id'];

        $plan = Plans::find($id);

        if (is_null($plan)) {
            return $this->sendTemporaryMessage('پلن پیدا نشد، دوباره تلاش کنید');
        }

        $fields = [
            'name' => ['label' => 'نام پلن', 'value' => $plan->name],
            'bandwidth' => ['label' => 'حجم', 'value' => $plan->bandwidth],
            'days' => ['label' => 'مدت زمان', 'value' => $plan->days],
            'price' => ['label' => 'قیمت (تومان)', 'value' => $plan->price],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];

        $text = "📦 <b>جزئیات پلن</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            // نمایش وضعیت در متن
            $valueText = $isEmpty
                ? '—'
                : htmlspecialchars((string)$field['value']);

            if ($key == 'type') {

                $service = Service::find($field['value']);
                if (!is_null($service)) {
                    $valueText = htmlspecialchars($service->name);
                } else {
                    $valueText = "--";
                }
            } elseif ($key == 'price') {
                $valueText = number_format($field['value']) . ' تومان ';
            } elseif ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    0 => '🟡 معلق',
                    default => '⚪ نامشخص',
                };
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            // دکمه‌ها
            $btnText = $isEmpty
                ? "🔴 " . $field['label']
                : $field['label'];

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminEditPlan|id={$id}|key={$key}"
            ];

            // هر دو دکمه یک ردیف
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            [
                'text' => 'حذف پلن',
                'callback_data' => "type=adminPlanDeleteDetail|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminPlans');


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminEditPlan($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminUpdatePlan');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['plan-key'] = $key;
        $telDetail['plan-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $plan = Plans::find($id);

        $fields = [
            'name' => ['label' => 'نام پلن', 'value' => $plan->name],
            'bandwidth' => ['label' => 'حجم', 'value' => $plan->bandwidth],
            'days' => ['label' => 'مدت زمان', 'value' => $plan->days],
            'price' => ['label' => 'قیمت (تومان)', 'value' => $plan->price],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];
        $oldValue = $plan->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        if ($key == 'bandwidth') {
            $text .= "📌 حجم را به گیگ وارد کنید";
        }
        if ($key == 'price') {
            $text .= "📌 مبلغ را به تومان وارد کنید";
        }

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {

            if ($key == 'type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    if ($service->id == $plan->type) {
                        $oldValue = $service->name;
                    }

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminUpdatePlan|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminUpdatePlan|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminUpdatePlan|id={$id}|value=1",
                    ],
                ];
            }

        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code> \n";


        $keyboard[] = $this->adminFooterButtons("type=adminPlanDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUpdatePlan($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;
        $key = $user->tel_detail['plan-key'];
        $id = $user->tel_detail['plan-id'];

        $fields = [
            'name' => ['label' => 'نام پلن'],
            'bandwidth' => ['label' => 'حجم'],
            'days' => ['label' => 'مدت زمان'],
            'price' => ['label' => 'قیمت'],
            'type' => ['label' => 'نوع'],
            'status' => ['label' => 'وضعیت'],
            'discount' => ['label' => 'درصد تخفیف'],

        ];

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            Plans::where('id', $id)->update([$key => $value]);

        } else {
            Plans::where('id', $id)->update([$key => $this->text]);
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminPlanDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPlanDeleteDetail($type)
    {
        $id = $type['id'];

        $service = Plans::find($id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ تعرفه موردنظر پیدا نشد.');
        }


        /*
        |--------------------------------------------------------------------------
        | Service Details
        |--------------------------------------------------------------------------
        */


        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف تعرفه\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این تعرفه اطمینان دارید؟\n\n";

        $text .= "📦 نام تعرفه:\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";

        $text .= "❗ پس از حذف، امکان بازگردانی تعرفه وجود نخواهد داشت.\n\n";

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminPlanDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminPlanDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPlanDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = Plans::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('تعرفه پیدا نشد');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "تعرفه با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);


        return $this->adminPlans(['page' => 1]);
    }

    // End Plans

    // Start Setting

    protected function adminSetting($type)
    {
        $keys = ['join-bot', 'join-with-referral', 'channel-join'];
        $settings = Setting::whereIn('key', $keys)->get();
        $buttons = [];

        foreach ($settings as $setting) {
            $buttons[] = [
                [
                    'text' => ($setting->value != 1 ? "✅" : null) . "غیرفعال",
                    'callback_data' => "type=adminSettingChangeValue|key={$setting->key}|value=-1"
                ], [
                    'text' => ($setting->value == 1 ? "✅" : null) . "فعال",
                    'callback_data' => "type=adminSettingChangeValue|key={$setting->key}|value=1"
                ],
                [
                    'text' => "{$setting->name}:",
                    'callback_data' => 'type=adminSettingSell',
                    'style' => 'primary'
                ],
            ];
        }
        $buttons[] = [
            [
                'text' => "═══════════════════",
                'callback_data' => 'type=adminSettingSell',
                'style' => 'danger'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminSettingSell'
            ],
            [
                'text' => "تنظیمات فروش",
                'callback_data' => 'type=adminSettingSell',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminPaymentSetting'
            ],
            [
                'text' => "تنظیمات پرداخت",
                'callback_data' => 'type=adminPaymentSetting',
                'style' => 'primary'
            ],
        ];
        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminBotSetting'
            ],
            [
                'text' => "تنظیمات ربات",
                'callback_data' => 'type=adminBotSetting',
                'style' => 'primary'
            ],
        ];


        $buttons[] = $this->adminFooterButtons();

        $text = headTitle('⚙️ تنظیمات سیستم');
        $text .= "
🔧 مدیریت تنظیمات ثبت‌نام، فروش،
پرداخت، رفرال و امکانات ربات

📌 لطفاً یکی از گزینه‌های زیر را انتخاب کنید.
";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');

    }

    protected function adminSettingChangeValue($data)
    {
        $key = $data['key'];
        $value = $data['value'];

        $setting = Setting::where('key', $key)->first();
        $setting->value = $value;
        $setting->save();

        return $this->adminSetting($data);
    }

    protected function adminSettingSell()
    {
        /*
        |--------------------------------------------------------------------------
        | Load or create settings
        |--------------------------------------------------------------------------
        */

        $settings = [
            'sell' => 'وضعیت فروش',
            'renew' => 'وضعیت تمدید',
            'extra' => 'وضعیت خرید حجم اضافه',
            'referral' => 'وضعیت پورسانت',
        ];

        $dataMap = [];

        foreach ($settings as $key => $label) {

            $row = Setting::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $label,
                    'value' => 0
                ]
            );

            $dataMap[$key] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = "⚙️ <b>تنظیمات فروش</b>\n\n";
        $text .= "در این بخش می‌توانید تنظیمات فروش را مدیریت کنید.\n";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        foreach ($dataMap as $key => $setting) {

            $isActive = ((int)$setting->value === 1);

            $statusText = $isActive ? '🟢 فعال' : '🔴 غیرفعال';

            $buttons[] = [
                [
                    'text' => "{$setting->name} ({$statusText})",
                    'callback_data' => "type=adminToggleSetting|key={$key}",
                    'style' => $isActive ? 'success' : 'danger'
                ]
            ];
        }
        $buttons[] = [
            [
                'text' => '💰  درصد پورسانت',
                'callback_data' => 'type=adminSettingCommission',
            ],
            [
                'text' => '📝 متن پورسانت',
                'callback_data' => 'type=adminSettingCommissionText',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Home button
        |--------------------------------------------------------------------------
        */
        $buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=adminSetting',
            ]
        ];

        $buttons[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
                'style' => 'primary'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminToggleSetting($type)
    {
        $key = $type['key'] ?? null;

        if (!$key) {
            return $this->sendTemporaryMessage('کلید تنظیمات نامعتبر است');
        }

        /*
        |--------------------------------------------------------------------------
        | Find or create setting
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => $key],
            [
                'name' => $key,
                'value' => 0
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Toggle value
        |--------------------------------------------------------------------------
        */

        $setting->value = ((int)$setting->value === 1) ? 0 : 1;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */

        $statusText = $setting->value == 1 ? 'فعال شد 🟢' : 'غیرفعال شد 🔴';

        /*
        |--------------------------------------------------------------------------
        | Alert
        |--------------------------------------------------------------------------
        */

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => $statusText,
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reload page
        |--------------------------------------------------------------------------
        */

        return $this->adminSettingSell();
    }

    protected function adminSettingCommission()
    {

        /*
        |--------------------------------------------------------------------------
        | Get or create setting
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => 'commission'],
            [
                'name' => 'درصد پورسانت',
                'value' => 0
            ]
        );

        $value = (int)$setting->value;

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = "⚙️ <b>تنظیم درصد پورسانت</b>\n\n";

        $text .= "💰 مقدار فعلی پورسانت: <b>{$value}%</b>\n\n";

        $text .= "✏️ لطفا درصد جدید را وارد کنید.\n";
        $text .= "این مقدار روی تمام محاسبات فروش اعمال خواهد شد.";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminSettingCommissionEdit()
    {
        /*
        |--------------------------------------------------------------------------
        | Get or create setting
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => 'commission'],
            [
                'name' => 'درصد پورسانت',
                'value' => 0
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Input
        |--------------------------------------------------------------------------
        */

        $value = trim($this->text);

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($value === '' || !is_numeric($value)) {
            return $this->sendTemporaryMessage('❌ لطفا فقط عدد وارد کنید');
        }

        $value = (float)$value;

        if ($value < 0 || $value > 100) {
            return $this->sendTemporaryMessage('❌ درصد باید بین 0 تا 100 باشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $setting->value = $value;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Success message
        |--------------------------------------------------------------------------
        */

        $text = "✅ <b>درصد پورسانت بروزرسانی شد</b>\n\n";
        $text .= "💰 مقدار جدید: <b>{$value}%</b>";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => '⚙️ بازگشت به تنظیمات پورسانت',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminSettingCommissionText()
    {
        /*
        |--------------------------------------------------------------------------
        | Get or create setting
        |--------------------------------------------------------------------------
        */


        $this->updatePath('adminSettingCommissionTextEdit');
        $setting = Setting::firstOrCreate(
            ['key' => 'commission_text'],
            [
                'name' => 'متن پورسانت',
                'value' => 'درصد پورسانت به صورت پیش‌فرض اعمال می‌شود.'
            ]
        );

        $value = $setting->value;

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = "📝 <b>تنظیم متن پورسانت</b>\n\n";

        $text .= "📌 متن فعلی:\n";
        $text .= "<code>{$value}</code>\n\n";

        $text .= "✏️ لطفا متن جدید را ارسال کنید.\n";
        $text .= "این متن در بخش فروش و توضیحات نمایش داده می‌شود.";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];


        $buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminSettingCommissionTextEdit()
    {
        /*
        |--------------------------------------------------------------------------
        | Get setting (or create fallback)
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => 'commission_text'],
            [
                'name' => 'متن پورسانت',
                'value' => ''
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | New value from user
        |--------------------------------------------------------------------------
        */

        $newText = trim($this->text);

        if (empty($newText)) {
            return $this->sendTemporaryMessage('❌ متن نمی‌تواند خالی باشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $setting->value = $newText;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Success message
        |--------------------------------------------------------------------------
        */

        $text = "✅ <b>متن پورسانت با موفقیت بروزرسانی شد</b>\n\n";

        $text .= "📌 متن جدید:\n";
        $text .= "<code>{$newText}</code>";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => '⚙️ بازگشت به تنظیمات',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        /*
        |--------------------------------------------------------------------------
        | Clear user state (اگر داری state system)
        |--------------------------------------------------------------------------
        */

        // $this->clearPath(); // اگر سیستم مسیر داری

        return $this->sendMessage($data, 'message');
    }

    protected function adminPaymentSetting()
    {
        $buttons = [];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'ignore'
            ],
            [
                'text' => "تنظیمات درگاه",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminCartSetting'
            ],
            [
                'text' => "تنظیمات کارت به کارت",
                'callback_data' => 'type=adminCartSetting',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'ignore'
            ],
            [
                'text' => "تنظیمات درگاه کریپتو",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [
            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminChargeAmount'
            ],
            [
                'text' => "مبالغ شارژ کیف پول",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ],
        ];


        $buttons[] = $this->adminFooterButtons("type=adminSetting");

        $text = "تنظیمات پرداخت";

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminCartSetting()
    {


        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("تنظیمات پرداخت");
        $text .= "در این بخش می‌توانید تنظیمات پرداخت را مدیریت کنید.\n";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $cartBeCart = Setting::firstOrCreate(
            ['key' => 'cart_be_cart'],
            [
                'name' => 'وضعیت کارت به کارت',
                'value' => 0,
            ]
        );

        $cartBeCartRandom = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_random'],
            [
                'name' => 'نمایش تصادفی کارت‌ها',
                'value' => 0,
            ]
        );

        $cartBeCartStatus = (int)$cartBeCart->value;
        $cartBeCartRandomStatus = (int)$cartBeCartRandom->value;

        /*
        |--------------------------------------------------------------------------
        | وضعیت کارت به کارت
        |--------------------------------------------------------------------------
        */

        $buttons[] = [
            [
                'text' => "💳 وضعیت کارت به کارت",
                'callback_data' => "ignore",
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => ($cartBeCartStatus === 1 ? '✅ ' : '') . "فعال",
                'callback_data' => "type=adminCartBeCartStatus|status=1",
            ],
            [
                'text' => ($cartBeCartStatus === 0 ? '✅ ' : '') . "غیرفعال",
                'callback_data' => "type=adminCartBeCartStatus|status=0",
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | نمایش تصادفی کارت‌ها
        |--------------------------------------------------------------------------
        */

        $buttons[] = [
            [
                'text' => "🎲 نمایش تصادفی کارت‌ها",
                'callback_data' => "ignore",
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => ($cartBeCartRandomStatus === 1 ? '✅ ' : '') . "فعال",
                'callback_data' => "type=adminCartBeCartRandom|status=1",
            ],
            [
                'text' => ($cartBeCartRandomStatus === 0 ? '✅ ' : '') . "غیرفعال",
                'callback_data' => "type=adminCartBeCartRandom|status=0",
            ],
        ];

//        $buttons[] = [
//            [
//                'text' => "متن صفحه کارت به کارت",
//                'callback_data' => "type=adminCartBeCartText",
//            ]
//        ];

        $buttons[] = [
            [
                'text' => "شماره کارت ها",
                'callback_data' => "type=adminCartList",
            ]
        ];


        $buttons[] = $this->adminFooterButtons('type=adminSetting');

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartBeCartStatus($type)
    {
        $status = $type['status'] ?? 0;

        Setting::where('key', 'cart_be_cart')
            ->update([
                'value' => $status
            ]);

        return $this->adminCartSetting();
    }

    protected function adminCartBeCartRandom($type)
    {
        $status = $type['status'] ?? 0;

        Setting::where('key', 'cart_be_cart_random')
            ->update([
                'value' => $status
            ]);

        return $this->adminCartSetting();
    }

    protected function adminCartBeCartText($type)
    {
        $this->updatePath('adminCartBeCartTextUpdate');

        $setting = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_text'],
            ['value' => '']
        );

        $oldValue = $setting->value ?? '—';

        $text = headTitle("💳 متن کارت به کارت");
        $text .= "✏️ لطفا متن کارت به کارت را وارد کنید.\n\n";
        $text .= "📌 متن فعلی:\n";
        $text .= "<code>" . htmlspecialchars($oldValue) . "</code>";

        $keyboard[] = $this->adminFooterButtons('type=adminCartSetting');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartBeCartTextUpdate()
    {
        $setting = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_text'],
            ['value' => '']
        );

        if (empty($this->text)) {
            return $this->sendTemporaryMessage('❌ متن نمی‌تواند خالی باشد');
        }

        $setting->value = $this->text;
        $setting->save();

        return $this->adminCartSetting();
    }

    protected function adminCartList()
    {

        $page = $type['page'] ?? 1;

        $list = Carts::orderbyDesc('id')
            ->paginate(10, ['*'], 'page', $page);


        $text = "🌍 <b>لیست کارت ها</b>\n";


        $keyboard = [];
        $row = [];
        foreach ($list as $country) {

            $row[] = [
                'text' => "{$country->cart}",
                'callback_data' => "type=adminCartDetail|id={$country->id}",
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminCountries');

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '➕ ایجاد کارت جدید',
                'callback_data' => 'type=adminCartCreate',
                'style' => 'success'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminCartSetting');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartCreate()
    {
        $user = $this->user;

        $country = new Carts();
        $country->status = 0;
        $country->admin_id = $user->id;
        $country->save();

        $type['id'] = $country->id;

        return $this->adminCartDetail($type);


    }

    protected function adminCartDetail($type)
    {
        $id = $type['id'];

        $country = Carts::find($id);

        if (is_null($country)) {
            return $this->sendTemporaryMessage('کارت پیدا نشد');
        }

        $fields = [
            'name' => [
                'label' => 'نام صاحب کارت',
                'value' => $country->name
            ],
            'cart' => [
                'label' => 'شماره کارت',
                'value' => $country->cart
            ],
            'status' => [
                'label' => 'وضعیت',
                'value' => $country->status
            ],
            'is_default' => [
                'label' => 'کارت پیشفرض',
                'value' => $country->is_default
            ],
        ];

        $text = "🌍 <b>جزئیات کارت</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = (
                $field['value'] === null ||
                $field['value'] === ''
            );

            /*
            |--------------------------------------------------------------------------
            | Value Text
            |--------------------------------------------------------------------------
            */

            if ($key == 'status' || $key == 'is_default') {

                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    default => '⚪️ نامشخص'
                };

            } else {

                $valueText = $isEmpty
                    ? '—'
                    : htmlspecialchars((string)$field['value']);
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            /*
            |--------------------------------------------------------------------------
            | Buttons
            |--------------------------------------------------------------------------
            */

            $btnText = $isEmpty
                ? "🔴 {$field['label']}"
                : "{$field['label']}";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminCartEdit|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=adminCartList');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];

        $user = $this->user;

        $this->updatePath('adminCartUpdate');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['cart-key'] = $key;
        $telDetail['cart-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $country = Carts::find($id);

        if (!$country) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => ['label' => 'نام صاحب حساب'],
            'cart' => ['label' => 'شماره کارت'],
            'status' => ['label' => 'وضعیت'],
            'is_default' => ['label' => 'کارت پیشفرض'],
        ];

        $oldValue = $country->$key ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Status Text
        |--------------------------------------------------------------------------
        */


        $customFiles = ['status', 'is_default'];
        if (in_array($key, $customFiles)) {

            if ($key == 'status' || $key == 'is_default') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminCartUpdate|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminCartUpdate|id={$id}|value=1",
                    ],
                ];
            }

        }


        if ($key == 'status' || $key == 'is_default') {

            $oldValue = match ((int)$oldValue) {
                1 => 'فعال',
                -1 => 'غیرفعال',
                default => 'نامشخص'
            };
        }

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>{$oldValue}</code>";

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = $this->adminFooterButtons("type=adminCartDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;

        $key = $user->tel_detail['cart-key'];
        $id = $user->tel_detail['cart-id'];

        $fields = [
            'name' => ['label' => 'نام صاحب کارت'],
            'status' => ['label' => 'وضعیت'],
            'cart' => ['label' => 'شماره کارت'],
            'is_default' => ['label' => 'کارت پیشفرض'],
        ];

        /*
        |--------------------------------------------------------------------------
        | Custom Fields
        |--------------------------------------------------------------------------
        */

        $customFields = ['status', 'is_default'];

        if (in_array($key, $customFields)) {
            $value = $type['value'];
        } else {
            $value = $this->text;
        }

        Carts::where('id', $id)
            ->update([$key => $value]);

        $text = "✅ فیلد <b>{$fields[$key]['label']}</b> با موفقیت بروزرسانی شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminCartDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminBotSetting()
    {
        /*
        |--------------------------------------------------------------------------
        | Settings (ensure existence)
        |--------------------------------------------------------------------------
        */

        $supportChannel = Setting::firstOrCreate(
            ['key' => 'support_id'],
            ['name' => 'آیدی کانال پشتیبانی', 'value' => 0]
        );

        $reportChannel = Setting::firstOrCreate(
            ['key' => 'report_id'],
            ['name' => 'آیدی کانال گزارشات', 'value' => 0]
        );

        $transactionChannel = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_id'],
            ['name' => 'آیدی کانال تایید تراکنشات', 'value' => 0]
        );

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("⚙️ تنظیمات ربات");
        $text .= "📌 در این بخش می‌توانید تنظیمات اصلی ربات را مدیریت کنید.\n\n";


        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [

        ];

        $buttons[] = [
            [
                'text' => "غیرفعال",
                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ],
            [
                'text' => "فعال",
                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ],
            [
                'text' => "ثبت نام ربات",
                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ]
        ];


        $buttons[] = [
            [
                'text' => "غیرفعال",

                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ], [
                'text' => "فعال",

                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ], [
                'text' => "جوین اجباری",
                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ]
        ];


        $buttons[] = [
            [
                'text' => "=====  آیدی کانال ها  =====",
                'callback_data' => "ignore",
                'style' => 'danger'
            ]
            ,
        ];

        $buttons[] = [
            [
                'text' => "💳 پشتیبانی",
                'callback_data' => "type=adminChangeSetting|k=support_id|p_path=adminBotSetting",
            ]
            , [
                'text' => "📊 گزارشات",
                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ]
        ];

        $buttons[] = [
            [
                'text' => "✅ تراکنشات",
                'callback_data' => "type=adminChangeSetting|k=cart_be_cart_id|p_path=adminBotSetting",
            ], [
                'text' => "✅ کانال",
                'callback_data' => "type=adminChangeSetting|k=cart_be_cart_id|p_path=adminBotSetting",
            ]
        ];

        $buttons[] = $this->adminFooterButtons('type=adminSetting');

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');
    }

    protected function adminChangeSetting($type)
    {
        $key = $type['k'] ?? null;
        $prevPath = $type['p_path'] ?? "adminSetting";

        if (!$key) {
            return $this->sendTemporaryMessage('⚠️ کلید تنظیمات مشخص نشده است.');
        }

        $this->updatePath('adminChangeSettingSubmit');

        $user = $this->user;
        $tel_detail = $user->tel_detail ?? [];

        $tel_detail['setting-key'] = $key;
        $tel_detail['setting-prev-path'] = $prevPath;

        $user->tel_detail = $tel_detail;
        $user->save();

        $setting = Setting::where('key', $key)->first();

        if (!$setting) {
            return $this->sendTemporaryMessage('⚠️ تنظیم مورد نظر یافت نشد.');
        }
        $currentValue = $setting->value ?? '—';

        $text = "✏️ <b>ویرایش تنظیم</b>\n\n";
        $text .= "⚙️ <b>{$setting->name}</b>\n";
        $text .= "📌 مقدار فعلی: <code>{$currentValue}</code>\n\n";
        $text .= "✍️ لطفا مقدار جدید را ارسال کنید.";

        $buttons = [];
        $buttons[] = $this->adminFooterButtons("type={$prevPath}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminChangeSettingSubmit($type = null)
    {
        $user = $this->user;
        $tel_detail = $user->tel_detail ?? [];

        $key = $tel_detail['setting-key'] ?? null;
        $prevPath = $tel_detail['setting-prev-path'] ?? 'adminSetting';

        if (!$key) {
            return $this->sendTemporaryMessage('⚠️ کلید تنظیمات یافت نشد.');
        }

        $setting = Setting::where('key', $key)->first();

        if (!$setting) {
            return $this->sendTemporaryMessage('⚠️ تنظیم مورد نظر یافت نشد.');
        }

        // مقدار جدید
        $value = $type['value'] ?? $this->text;

        if ($key == 'support_id' || $key == 'report_id' || $key == 'cart_be_cart_id') {
            $value = str_replace('https://t.me/', '', $value);
            $value = str_replace('@', '', $value);
        }

        if ($value === null || $value === '') {
            return $this->sendTemporaryMessage('❌ مقدار وارد شده معتبر نیست.');
        }

        // ذخیره مقدار
        $setting->value = $value;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        $text = "✅ تنظیم با موفقیت بروزرسانی شد\n\n";
        $text .= "⚙️ <b>{$setting->name}</b>\n";
        $text .= "📌 مقدار جدید: <code>{$setting->value}</code>";

        $buttons[] = $this->adminFooterButtons("type={$prevPath}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    //Country

    protected function adminCountries($type)
    {
        $page = $type['page'] ?? 1;

        $list = Countries::orderByDesc('id')
            ->with('Service')
            ->paginate(10, ['*'], 'page', $page);

        $text = "🌍 <b>لیست کشورها</b>\n";
        $text .= "📄 صفحه: <code>{$list->currentPage()}</code>\n";
        $text .= "📊 تعداد: <code>{$list->total()}</code>\n\n";

        $keyboard = [];
        $row = [];
        foreach ($list as $country) {

            $status = ((int)$country->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $name = !is_null($country->Service) ? $country->Service->name : '-';
            $row[] = [
                'text' => "{$country->name} | {$name} | {$status}",
                'callback_data' => "type=adminCountriesDetail|id={$country->id}",
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminCountries');

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '➕ ایجاد کشور جدید',
                'callback_data' => 'type=adminCountriesCreate',
                'style' => 'success'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCountriesCreate($type)
    {
        $user = $this->user;

        $country = new Countries();
        $country->status = -1;
        $country->save();

        $type['id'] = $country->id;

        return $this->adminCountriesDetail($type);
    }

    protected function adminCountriesDetail($type)
    {
        $id = $type['id'];

        $country = Countries::find($id);

        if (is_null($country)) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => [
                'label' => 'نام کشور',
                'value' => $country->name
            ],
            'status' => [
                'label' => 'وضعیت',
                'value' => $country->status
            ],
            'type' => [
                'label' => 'نوع',
                'value' => $country->type
            ],
        ];

        $text = "🌍 <b>جزئیات کشور</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = (
                $field['value'] === null ||
                $field['value'] === ''
            );

            /*
            |--------------------------------------------------------------------------
            | Value Text
            |--------------------------------------------------------------------------
            */

            if ($key == 'status') {

                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    default => '⚪️ نامشخص'
                };

            } elseif ($key == 'type') {
                $service = Service::find($field['value']);

                $valueText = !is_null($service) ? $service->name : '-';
            } else {

                $valueText = $isEmpty
                    ? '—'
                    : htmlspecialchars((string)$field['value']);
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            /*
            |--------------------------------------------------------------------------
            | Buttons
            |--------------------------------------------------------------------------
            */

            $btnText = $isEmpty
                ? "🔴 {$field['label']}"
                : "🟢 {$field['label']}";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminCountriesEdit|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=adminCountries');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCountriesEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];

        $user = $this->user;

        $this->updatePath('adminCountriesUpdate');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['country-key'] = $key;
        $telDetail['country-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $country = Countries::find($id);

        if (!$country) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => ['label' => 'نام کشور'],
            'status' => ['label' => 'وضعیت'],
            'type' => [
                'label' => 'نوع',
            ],
        ];

        $oldValue = $country->$key ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Status Text
        |--------------------------------------------------------------------------
        */


        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {

            if ($key == 'type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    if ($service->id == $country->type) {
                        $oldValue = $service->name;
                    }

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminCountriesUpdate|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminCountriesUpdate|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminCountriesUpdate|id={$id}|value=1",
                    ],
                ];
            }

        }


        if ($key == 'status') {

            $oldValue = match ((int)$oldValue) {
                1 => 'فعال',
                -1 => 'غیرفعال',
                default => 'نامشخص'
            };
        }

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>{$oldValue}</code>";

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = $this->adminFooterButtons("type=adminCountriesDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCountriesUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;

        $key = $user->tel_detail['country-key'];
        $id = $user->tel_detail['country-id'];

        $fields = [
            'name' => ['label' => 'نام کشور'],
            'status' => ['label' => 'وضعیت'],
            'type' => ['label' => 'نوع'],
        ];

        /*
        |--------------------------------------------------------------------------
        | Custom Fields
        |--------------------------------------------------------------------------
        */

        $customFields = ['status', 'type'];

        if (in_array($key, $customFields)) {

            $value = $type['value'];

            Countries::where('id', $id)
                ->update([$key => $value]);

        } else {

            Countries::where('id', $id)
                ->update([$key => $this->text]);
        }

        $text = "✅ فیلد <b>{$fields[$key]['label']}</b> با موفقیت بروزرسانی شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminCountriesDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }


    // Admin Service
    protected function adminService($type)
    {
        $page = $type['page'] ?? 1;

        $list = Service::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $text = "🌍 <b>لیست سرویس ها</b>\n";
        $text .= "📄 صفحه: <code>{$list->currentPage()}</code>\n";
        $text .= "📊 تعداد: <code>{$list->total()}</code>\n\n";

        $keyboard = [];
        $row = [];


        foreach ($list as $country) {

            $status = ((int)$country->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $name = !is_null($country->name) ? $country->name : 'بدون نام';
            $row[] = [
                'text' => "{$name} | {$status}",
                'callback_data' => "type=adminServiceDetail|id={$country->id}",
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminService');

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '➕ ایجاد سرویس جدید',
                'callback_data' => 'type=adminServiceCreate',
                'style' => 'success'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceCreate($type)
    {
        $user = $this->user;

        $country = new Service();
        $country->status = 0;
        $country->save();

        $type['id'] = $country->id;

        return $this->adminServiceDetail($type);
    }

    protected function adminServiceDetail($type)
    {
        $id = $type['id'];

        $country = Service::find($id);

        if (is_null($country)) {
            return $this->sendTemporaryMessage('سرویس پیدا نشد');
        }

        $fields = [
            'name' => [
                'label' => 'نام سرویس',
                'value' => $country->name
            ],
            'status' => [
                'label' => 'وضعیت',
                'value' => $country->status
            ],
            'price_per_gb' => [
                'label' => 'قیمت هر گیگ',
                'value' => $country->price_per_gb
            ],
        ];

        $text = "🌍 <b>جزئیات سرویس</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = (
                $field['value'] === null ||
                $field['value'] === ''
            );

            /*
            |--------------------------------------------------------------------------
            | Value Text
            |--------------------------------------------------------------------------
            */

            if ($key == 'status') {

                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    default => '⚪️ نامشخص'
                };

            } elseif ($key == 'price_per_gb') {
                $valueText = number_format($field['value']) . ' تومان ';
            } else {

                $valueText = $isEmpty
                    ? '—'
                    : htmlspecialchars((string)$field['value']);
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            /*
            |--------------------------------------------------------------------------
            | Buttons
            |--------------------------------------------------------------------------
            */

            $btnText = $isEmpty
                ? "🔴"
                : "🟢";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminServiceEdit|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        $text .= "راهنما: \n قیمت هر گیگ فقط برای فروش حجم اضافه محاسبه می شود . بر روی تعرفه ها هیچ تاثیری ندارد.\n\n";

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            ['text' => 'حذف سرویس',
                'callback_data' => "type=adminServiceDeleteDetail|id={$id}",
                'style' => 'danger']
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminService');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];

        $user = $this->user;

        $this->updatePath('adminServiceUpdate');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['service-key'] = $key;
        $telDetail['service-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $country = Service::find($id);

        if (!$country) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => ['label' => 'نام سرویس'],
            'status' => ['label' => 'وضعیت'],
            'price_per_gb' => [
                'label' => 'قیمت هر گیگ',
            ],
        ];

        $oldValue = $country->$key ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Status Text
        |--------------------------------------------------------------------------
        */

        if ($key == 'status') {

            $oldValue = match ((int)$oldValue) {
                1 => 'فعال',
                -1 => 'غیرفعال',
                default => 'نامشخص'
            };
        }

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>{$oldValue}</code>";

        /*
        |--------------------------------------------------------------------------
        | Status Buttons
        |--------------------------------------------------------------------------
        */

        if ($key == 'status') {

            $keyboard[] = [
                [
                    'text' => '🟢 فعال',
                    'callback_data' => "type=adminServiceUpdate|id={$id}|value=1",
                    'style' => 'success'
                ],
                [
                    'text' => '🔴 غیرفعال',
                    'callback_data' => "type=adminServiceUpdate|id={$id}|value=-1",
                    'style' => 'danger'
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons("type=adminServiceDetail|id={$id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;

        $key = $user->tel_detail['service-key'];
        $id = $user->tel_detail['service-id'];

        $fields = [
            'name' => ['label' => 'نام سرویس'],
            'status' => ['label' => 'وضعیت'],
            'price_per_gb' => [
                'label' => 'قیمت هر گیگ',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Custom Fields
        |--------------------------------------------------------------------------
        */

        $customFields = ['status'];

        if (in_array($key, $customFields)) {

            $value = $type['value'];

            Service::where('id', $id)
                ->update([$key => $value]);

        } else {

            Service::where('id', $id)
                ->update([$key => $this->text]);
        }

        $text = "✅ فیلد <b>{$fields[$key]['label']}</b> با موفقیت بروزرسانی شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminServiceDetail|id={$id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceDeleteDetail($type)
    {
        $id = $type['id'];

        $service = Service::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ سرویس موردنظر پیدا نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Check Usage
        |--------------------------------------------------------------------------
        */

        if ($service->is_used) {
            return $this->sendTemporaryMessage('⚠️ این سرویس در حال استفاده است و امکان حذف آن وجود ندارد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Service Details
        |--------------------------------------------------------------------------
        */

        $plansUsed = Plans::where('type', $service->id)
            ->pluck('name')
            ->toArray();
        $plansUsed = !empty($plansUsed) ? implode(',', $plansUsed) : '—';
        $panelUsed = Panels::where('panel_type', $service->id)
            ->pluck('name')
            ->toArray();

        $panelUsed = !empty($panelUsed) ? implode(',', $panelUsed) : '—';

        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف سرویس\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این سرویس اطمینان دارید؟\n\n";

        $text .= "📦 نام سرویس:\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";

        $text .= "📊 پنل های فعال:\n";
        $text .= "<code>{$panelUsed}</code>\n\n";

        $text .= "📊 پلن های فعال:\n";
        $text .= "<code>{$plansUsed}</code>\n\n";

        $text .= "❗ پس از حذف، امکان بازگردانی سرویس وجود نخواهد داشت.\n\n";

        if (!empty($panelUsed) || !empty($plansUsed)) {
            $text .= "❗ تازمانی که این سرویس٬ پنل یا پلن فعالی داشته باشد امکان حذف آن وجود ندارد";

        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminServiceDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminServiceDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = Service::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس پیدا نشد');
        }

        $plansUsed = Plans::where('type', $service->id)->first();
        $panelUsed = Panels::where('panel_type', $service->id)->first();

        if (!is_null($plansUsed) || !is_null($panelUsed)) {
            return $this->sendTemporaryMessage('این سرویس در حال استفاده می باشد.');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "سرویس با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);


        return $this->adminService(['page' => 1]);
    }


    // Extra Bandwidth
    protected function adminExtraBandwidths($type)
    {
        $page = $type['page'] ?? 1;

        $list = ExtraBandwidth::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $text = "📦 <b>لیست حجم های اضافه</b>\n";
        $text .= "مبلغ هر حجم اضافه به ازای هر گیگ محاسبه میشود.\n\n";
        $text .= "قیمت به ازای هر گیگ  میاشد. قیمت هر گیگ در قسمت تنظیمات همین صفحه قابل تنظیم می باشد.\n\n";
        $text .= "حهت فعال یا غیرفعال کردن فروش حجم اضافه از طریق تنظمیات اقدام نمایید.\n\n.";

        $keyboard = [];
        $row = [];


        foreach ($list as $plan) {

            // نوع پلن
            $planType = Service::find($plan->type);
            if (!is_null($planType)) {
                $planType = $planType->name;
            } else {
                $planType = '--';
            }

            // وضعیت
            $status = ((int)$plan->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $row[] = [
                'text' => "حجم:{$plan->name} گیگ| {$planType} | {$status}",
                'callback_data' => "type=adminExtraBandwidthsDetail|id={$plan->id}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        // صفحه‌بندی
        $pagination = $this->paginationFooterButton($list, $page, 'adminExtraBandwidths');
        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        // ایجاد پلن
        $keyboard[] = [
            [
                'text' => 'تنظیمات',
                'callback_data' => 'type=adminPlanCreate',
                'style' => 'primary'
            ],
            [
                'text' => '➕ ایجاد حجم اضافه جدید',
                'callback_data' => 'type=adminExtraBandwidthsCreate',
                'style' => 'success'
            ],

        ];

        // برگشت

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsCreate($type)
    {
        $user = $this->user;

        $newPanel = new ExtraBandwidth();
        $newPanel->admin_id = $user->id;
        $newPanel->status = 0;
        $newPanel->save();

        $type['id'] = $newPanel->id;
        return $this->adminExtraBandwidthsDetail($type);
    }

    protected function adminExtraBandwidthsDetail($type)
    {
        $id = $type['id'];

        $plan = ExtraBandwidth::find($id);

        if (is_null($plan)) {
            return $this->sendTemporaryMessage('حجم اضافه پیدا نشد، دوباره تلاش کنید');
        }

        $fields = [
            'name' => ['label' => 'مقدار حجم', 'value' => $plan->name],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];

        $text = "📦 <b>جزئیات حجم اضافه</b>\n\n";

        $keyboard = [];
        $row = [];
        $pricePerGb = null;
        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            // نمایش وضعیت در متن
            $valueText = $isEmpty
                ? '—'
                : htmlspecialchars((string)$field['value']);

            if ($key == 'type') {

                $service = Service::find($field['value']);
                if (!is_null($service)) {
                    $pricePerGb = $service->price_per_gb;
                    $valueText = htmlspecialchars($service->name);
                } else {
                    $valueText = "--";
                }
            } elseif ($key == 'price') {
                $valueText = number_format($field['value']) . ' تومان ';
            } elseif ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    0 => '🟡 معلق',
                    default => '⚪ نامشخص',
                };
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            // دکمه‌ها
            $btnText = $isEmpty
                ? "🔴 " . $field['label']
                : $field['label'];

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminExtraBandwidthsEdit|id={$id}|key={$key}"
            ];

            // هر دو دکمه یک ردیف
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        if (is_null($plan->name)) {
            $text .= "▪️ <b>برای محاسبه مبلغ لطفا حجم را وارد نمایید</b>\n\n";
        } elseif (isset($service) && $pricePerGb == 0 && is_null($pricePerGb)) {

            $keyboard[] = [
                [
                    'text' => 'تنظیم قیمت هر گیگ',
                    'callback_data' => "type=adminServiceDetail|id={$service->id}",
                    'style' => 'primary'
                ]
            ];
        } elseif (!isset($service)) {
            $text .= "▪️ <b>برای محاسبه مبلغ لطفا نوع را انتخاب نمایید</b>\n\n";
        } else {
            $price = calculateExtraDiscount($plan, $pricePerGb);

            $basePrice = number_format($price['basePrice']);
            $price = number_format($price['price']);

            $text .= "▪️ <b>مبلغ</b>: <code>{$basePrice}</code> تومان\n";
            $text .= "▪️ <b>مبلغ با احتساب تخفیف</b>: <code>{$price}</code> تومان\n";
        }

        $keyboard[] = [
            [
                'text' => 'حذف آیتم',
                'callback_data' => "type=adminExtraBandwidthsDelete|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminExtraBandwidths');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminExtraBandwidthsUpdate');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['extra-key'] = $key;
        $telDetail['extra-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $plan = ExtraBandwidth::find($id);

        $fields = [
            'name' => ['label' => 'مقدار حجم', 'value' => $plan->name],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];
        $oldValue = $plan->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        if ($key == 'name') {
            $text .= "📌 حجم را به گیگ وارد کنید";
        }

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {

            if ($key == 'type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    if ($service->id == $plan->type) {
                        $oldValue = $service->name;
                    }

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminExtraBandwidthsUpdate|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminExtraBandwidthsUpdate|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminExtraBandwidthsUpdate|id={$id}|value=1",
                    ],
                ];
            }

        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code> \n";


        $keyboard[] = $this->adminFooterButtons("type=adminExtraBandwidthsDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;
        $key = $user->tel_detail['extra-key'];
        $id = $user->tel_detail['extra-id'];

        $fields = [
            'name' => ['label' => 'مقدار حجم'],
            'type' => ['label' => 'نوع'],
            'status' => ['label' => 'وضعیت'],
            'discount' => ['label' => 'درصد تخفیف'],
        ];

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            ExtraBandwidth::where('id', $id)->update([$key => $value]);

        } else {
            ExtraBandwidth::where('id', $id)->update([$key => $this->text]);
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminExtraBandwidthsDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsDelete($type)
    {
        $id = $type['id'];

        $service = ExtraBandwidth::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ سرویس موردنظر پیدا نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Check Usage
        |--------------------------------------------------------------------------
        */

        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف آیتم\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این آیتم اطمینان دارید؟\n\n";

        $text .= "📦 مقدار حجم (گیگ):\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";


        $text .= "❗ پس از حذف، امکان بازگردانی سرویس وجود نخواهد داشت.\n\n";


        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminExtraBandwidthsDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminExtraBandwidthsDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = ExtraBandwidth::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس پیدا نشد');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "سرویس با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        return $this->adminExtraBandwidths(['page' => 1]);
    }


    // Inbounds
    protected function adminInbounds($data)
    {
        $keyboard = [];
        $keyboard[] = [
            [
                'text' => "اینبوند های ثنایی",
                'callback_data' => "type=adminInboundList|value=sanaie"
            ], [
                'text' => "اینبوند های پاسارگاد",
                'callback_data' => "type=adminPasarGuardGroups|value=pasarguard"
            ],
        ];
        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $text = headTitle('لیست اینبوند ها');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminInboundList($data)
    {
        $type = $data['value'];
        $page = $data['page'] ?? 1;

        /*
        |--------------------------------------------------------------------------
        | Panels
        |--------------------------------------------------------------------------
        */

        $panels = Panels::where('system_type', $type)
            ->pluck('id')
            ->toArray();
        /*
        |--------------------------------------------------------------------------
        | Inbounds List
        |--------------------------------------------------------------------------
        */

        $list = Inbounds::wherein('panel_id', $panels)
            ->with(['panel'])
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);
        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle('لیست اینبوند های ثنایی');

        if ($list->count() == 0) {
            $text .= "\n❌ هیچ اینبوندی یافت نشد.";
        }

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        foreach ($list as $inbound) {
            $name = $inbound?->panel?->name;
            $statusIcon = $inbound->status ? '🟢' : '🔴';
            $keyboard[][] = [
                'text' => "{$statusIcon} {$inbound->remark}:{$inbound->port} - نام پنل: {$name}",
                'callback_data' => "type=adminToggleInboundStatus|id={$inbound->id}|value={$type}"
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton(
            $list,
            $page,
            "adminInboundList|value={$type}"
        );

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Footer Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=adminInbounds');

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminToggleInboundStatus($data)
    {
        $id = $data['id'];

        $inbound = Inbounds::find($id);
        if ($inbound->status == 0) {
            $inbound->status = 1;
            $message = ' `فعال` ';
        } else {
            $inbound->status = 0;
            $message = ' `غیر فعال` ';
        }
        $inbound->save();


        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "وضعیت اینبوند با موفقیت به {$message}تغییر پیدا کرد. ",
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        return $this->adminInboundList($data);

    }

    protected function adminPasarGuardGroups($data)
    {
        $type = $data['value'] ?? 'pasarguard';
        $id = $data['id'] ?? null;
        $page = $data['page'] ?? 1;


        $path = null;
        if (!is_null($id)) {
            $panels = [$id];
            $path = "|b=true|v_id=$id";
        } else {
            $panels = Panels::where('system_type', 'pasarguard');
            $panels = $panels->pluck('id')->toArray();
        }

        $list = Inbounds::wherein('panel_id', $panels)
            ->with(['panel'])
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        $text = headTitle('لیست گروه های پاسارگاد');

        if ($list->count() == 0) {
            $text .= "\n❌ هیچ اینبوندی یافت نشد.";
        }

        $keyboard = [];
        foreach ($list as $inbound) {
            $name = $inbound?->panel?->name;
            $statusIcon = $inbound->status == 1 ? '🟢' : '🔴';
            $keyboard[][] = [
                'text' => "{$statusIcon} {$inbound->remark}:{$inbound->port} - نام پنل: {$name}",
                'callback_data' => "type=AdminPGGDA|id={$inbound->id}|value={$type}{$path}"
            ];
        }


        $pagination = $this->paginationFooterButton(
            $list,
            $page,
            "adminInboundList|value={$type}"
        );

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }
        if (!is_null($id)) {
            $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id=$id");
        } else {
            $keyboard[] = $this->adminFooterButtons('type=adminInbounds');
        }
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminPasarGuardGroupDetail($data)
    {
        $id = $data['id'];
        $back = $data['b'] ?? null;
        $value = $data['v_id'] ?? null;

        $plan = Inbounds::find($id);

        if (is_null($plan)) {
            return $this->sendTemporaryMessage('گروه پیدا نشد، دوباره تلاش کنید');
        }

        $fields = [
            'country_id' => ['label' => 'نام کشور', 'value' => $plan->country_id],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],

        ];

        $text = "📦 <b>جزئیات گروه</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            // نمایش وضعیت در متن
            $valueText = $isEmpty
                ? '—'
                : htmlspecialchars((string)$field['value']);

            if ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    0 => '🟡 معلق',
                    default => '⚪ نامشخص',
                };
            } elseif ($key == 'country_id') {
                $valueText = Countries::find($field['value'])->name ?? '—';
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            // دکمه‌ها
            $btnText = $isEmpty
                ? "🔴 " . $field['label']
                : $field['label'];

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminPasarGuardGroupsEdit|id={$id}|key={$key}"
            ];

            // هر دو دکمه یک ردیف
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            [
                'text' => 'حذف پلن',
                'callback_data' => "type=adminPlanDeleteDetail|id={$id}",
                'style' => 'danger'
            ]
        ];

        if (is_null($back)) {
            $keyboard[] = $this->adminFooterButtons('type=adminPasarGuardGroups');
        } else {
            $keyboard[] = $this->adminFooterButtons("type=adminPasarGuardGroups|id={$value}");
        }


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPasarGuardGroupsEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminPasarGuardGroupUpdate');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['inbound-key'] = $key;
        $telDetail['inbound-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $fields = [
            'country_id' => ['label' => 'کشور'],
            'status' => ['label' => 'وضعیت'],
        ];

        $panel = Inbounds::find($id);

        $oldValue = $panel->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";

        $customFiles = ['system_type', 'type', 'panel_type', 'status', 'country_id'];
        if (in_array($key, $customFiles)) {

            if ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminPasarGuardGroupsUpdate|id={$id}|value=1",
                    ],
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminPasarGuardGroupsUpdate|id={$id}|value=-1",
                    ],
                ];
            } elseif ($key == 'country_id') {
                $parentPanel = Panels::find($panel->panel_id);
                $country = Countries::where('type', $parentPanel->panel_type)->get();
                foreach ($country as $item) {

                    $isSelected = ((int)$panel->country_id === (int)$item->id);

                    if ($isSelected) {
                        $oldValue = $item->name;
                    }
                    $keyboard[] = [
                        [
                            'text' => $item->name . ($isSelected ? ' ✅' : ''),
                            'callback_data' => "type=adminPasarGuardGroupsUpdate|id={$id}|value={$item->id}",
                            'style' => $isSelected ? 'success' : ''
                        ]
                    ];
                }
            }
        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code>";


        $keyboard[] = $this->adminFooterButtons("type=AdminPGGDA|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminPasarGuardGroupsUpdate($type = null)
    {
        $user = $this->user;
        $key = $user->tel_detail['inbound-key'];
        $id = $user->tel_detail['inbound-id'];

        $fields = [
            'country_id' => ['label' => 'کشور'],
            'status' => ['label' => 'وضعیت'],
        ];


        $customFiles = ['status', 'country_id'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            Inbounds::where('id', $id)->update([$key => $value]);

        } else {
            $text = rtrim($this->text, '/');
            Inbounds::where('id', $id)->update([$key => $text]);

            if ($key == 'url') {
                $checkSub = Panels::find($id);
                if (is_null($checkSub->sub_address)) {
                    $scheme = parse_url($text, PHP_URL_SCHEME) ?? 'https';
                    $host = parse_url($text, PHP_URL_HOST);
                    $result = $scheme . '://' . $host . ':2096/sub/';
                    $checkSub->sub_address = $result;
                    $checkSub->save();
                }
            }
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=AdminPGGDA|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }


    protected function adminChargeAmount($data)
    {
        $setting = Setting::where('key', 'charge_amount')->first();

        $keyboard = [
            'inline_keyboard' => []
        ];

        if (!is_null($setting) && !empty($setting->value)) {

            $amounts = explode(',', $setting->value);

            $row = [];

            foreach ($amounts as $key => $amount) {

                $amount = number_format(trim($amount));

                if ($amount === '') {
                    continue;
                }

                $row[] = [
                    'text' => "💰 {$amount} T",
                    'callback_data' => "type=adminChargeAmountDelete|amount={$amount}|key=$key",
                ];

                // دو ستون در هر ردیف
                if (count($row) == 2) {
                    $keyboard['inline_keyboard'][] = $row;
                    $row = [];
                }
            }

            // اگر تعداد فرد بود
            if (!empty($row)) {
                $keyboard['inline_keyboard'][] = $row;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | دکمه بازگشت
        |--------------------------------------------------------------------------
        */

        $keyboard['inline_keyboard'][][] = [
            'text' => "افزودن مبلغ",
            'callback_data' => "type=adminChargeAmountAdd",
        ];

        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminPaymentSetting");

        $text = "
💳 <b>مدیریت مبالغ شارژ</b>

یکی از مبالغ زیر را برای مدیریت یا تنظیم انتخاب کنید.
";

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');
    }

    protected function adminChargeAmountAdd()
    {
        $this->updatePath('adminChargeAmountSubmit');

        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminPaymentSetting");

        $text = "لطفا مبلع مورد نظر را به تومان وارد نمایید";

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');
    }

    protected function adminChargeAmountSubmit($data)
    {
        $input = trim($this->text);


        $validator = Validator::make(
            ['amount' => PersianNumToEn($input)],
            [
                'amount' => 'required|numeric|min:0',
            ],
            [
                'amount.required' => 'مبلغ الزامی است',
                'amount.numeric' => 'فقط عدد مجاز است',
                'amount.min' => 'مبلغ نمی‌تواند منفی باشد',
            ]
        );
        if ($validator->fails()) {
            return $this->sendTemporaryMessage($validator->errors()->first());
        }

        $amounts = [];

        $setting = Setting::where('key', 'charge_amount')->first();
        if (!is_null($setting) && !empty($setting->value)) {
            $amounts = explode(',', $setting->value);
        }
        $amounts[] = $this->text;
        $amounts = array_map('trim', $amounts);
        $amounts = array_filter($amounts, fn($v) => $v !== '');

        $amounts = array_map('floatval', $amounts);

        sort($amounts, SORT_NUMERIC);

        $setting->value = implode(',', $amounts);
        $setting->save();


        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminChargeAmount");

        $text = "مبلغ مورد نظر با موفقیت ثبت شد.";

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');

    }

    protected function adminChargeAmountDelete($data)
    {
        $key = $data['key'];
        $setting = Setting::where('key', 'charge_amount')->first();

        if (!is_null($setting) && !empty($setting->value)) {
            $amounts = explode(',', $setting->value);
            unset($amounts[$key]);

            $setting->value = implode(',', $amounts);
            $setting->save();
        }

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "مبلغ با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        return $this->adminChargeAmount($data);
    }

    protected function adminMessage($data)
    {
        $message = Message::ordebyDesc('id')->paginate(10);

    }

    // Orders
    protected function adminOrdersList($data)
    {
        $page = $data['page'] ?? 1;
        $filter = $data['filter'] ?? null;
        $search = $data['search'] ?? null;
        $userId = $data['userId'] ?? null;

        $query = Orders::query();

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
        if (is_null($userId)) {
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {

                    if (is_numeric($search)) {
                        $q->where('id', (int)$search);
                    }

                    $q->orWhere('uid', 'LIKE', "%{$search}%")
                        ->orWhere('remark', 'LIKE', "%{$search}%")
                        ->orWhereIn('user_id', function ($userQuery) use ($search) {
                            $userQuery->select('id')
                                ->from('users')
                                ->where('username', 'LIKE', "%{$search}%")
                                ->orWhere('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%");
                        });
                });
            }
        } else {
            $query->where('user_id', $userId);
        }


        if (!empty($filter)) {
            $query->where('status', $filter);
        }

        $orders = $query
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        $text = headTitle("👥 لیست سفارشات");
        $text .= "
🔎 جستجو بر اساس:
• آیدی سفارش
• نام کاربری
• ریمارک

📌 برای مشاهده جزئیات،
روی سفارش موردنظر کلیک کنید.
";

        if ($filter) {
            $text .= "📌 فیلتر فعال: <code>{$filter}</code>\n\n";
        }

        if ($search) {
            $text .= "🔍 جستجوی فعال: <code>{$search}</code>\n\n";
        }

        $keyboard = [];
        $row = [];

        /*
        |--------------------------------------------------------------------------
        | Order Buttons
        |--------------------------------------------------------------------------
        */

        foreach ($orders as $order) {

            $btnText = "#" . $order->id;
            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminOrderSingle|id={$order->id}|search=$search|userId=$userId"
            ];
            // دو ستونه
            if (count($row) == 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        // باقی مانده
        if (!empty($row)) {
            $keyboard[] = $row;
        }

        if ($orders->isEmpty()) {
            $keyboard[] = [
                [
                    'text' => 'سفارشی یافت نشد',
                    'callback_data' => 'ignore'
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = [];

        $callbackBase = 'type=adminOrdersList';

        if (!empty($filter)) {
            $callbackBase .= '|filter=' . $filter;
        }

        if (!empty($search)) {
            $callbackBase .= '|search=' . $search;
        }

        if ($orders->currentPage() > 1) {

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => $callbackBase . '|page=' . ($page - 1)
            ];
        } else {
            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'ignore'
            ];
        }

        $pagination[] = [
            'text' => "📄 {$orders->currentPage()} / {$orders->lastPage()}",
            'callback_data' => 'ignore'
        ];

        if ($orders->hasMorePages()) {

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => $callbackBase . '|page=' . ($page + 1)
            ];
        } else {
            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'ignore'
            ];
        }

        $keyboard[] = $pagination;

        /*
        |--------------------------------------------------------------------------
        | Filter Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = [
            [
                'text' => '🔍 جستجو',
                'callback_data' => 'type=adminOrderSearch'
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Home Button
        |--------------------------------------------------------------------------
        */

        if (!is_null($userId)){
            $keyboard[] = $this->adminFooterButtons("type=adminUserDetail|id=$userId");
        }else{
            $keyboard[] = $this->adminFooterButtons('type=admin-home');
        }

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminOrderSearch($type)
    {
        $text = headTitle("👥جستجو سفارشات");
        $text .= "
🔎 جستجو بر اساس:
• آیدی سفارش
• نام کاربری
• ریمارک

📌 برای مشاهده جزئیات،
روی کاربر موردنظر کلیک کنید.
";

        $keyboard[] = $this->adminFooterButtons('type=adminOrderList');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->updatePath('adminOrdersList');

        return $this->sendMessage($data, 'message');
    }

    protected function adminOrderSingle($data)
    {
        $id = $data['id'] ?? null;
        $userId = $data['userId'] ?? null;
        $search = $data['search'] ?? null;
        if (!$id) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "❌ <b>سفارش نامعتبر است!</b>\n\nلطفا دوباره از بخش «سرویس های من» سفارش خود را انتخاب کنید.",
                'parse_mode' => 'HTML',
            ]);
        }

        $order = Orders::where('id', $id)
            ->first();

        $targetUser = User::find($order->user_id);
        if (is_null($order)) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "🚫 <b>سفارش یافت نشد</b>\n\nاین سفارش وجود ندارد یا متعلق به حساب شما نیست.",
                'parse_mode' => 'HTML',
            ]);
        }

        $detail = is_array($order->detail)
            ? $order->detail
            : json_decode($order->detail, true);

        $buttons = [];


        $buttons[] = [
            [
                'text' => '✏️ تغییر نام',
                'callback_data' => "type=clientChangeConfigName|id={$order->id}",
            ],
            [
                'text' => '🔗 تغییر کد',
                'callback_data' => "type=clientChangeConfigUid|id={$order->id}",
            ],
        ];

        $buttons[] = [
            [
                'text' => '➕ تغییر حجم',
                'callback_data' => "type=adminOrderChangeBw|id={$order->id}",
            ],
            [
                'text' => '🔄 تغییر زمان',
                'callback_data' => "type=adminOrderChangeTime|id={$order->id}",
            ],
        ];

        $buttons[] = [
            [
                'text' => '📚 تغییر وضعیت',
                'callback_data' => "type=clientGuides|id={$order->id}",
            ],
        ];

        $buttons[] = [
            [
                'text' => 'نمایش کد',
                'callback_data' => "type=adminOrderShowCode|id={$order->id}",
            ],
        ];

        $buttons[] = [

            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
            ],
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=adminOrdersList|search=$search|userId=$userId",
            ],
        ];

        $jdf = new Jdf();

        $data = getConfigDetail($order);
        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $totalUsed = $data['data']['totalUsed'];
            $left = $data['data']['left'];
            $code = $data['data']['code'];
            $expireTime = $data['data']['code'] ? $jdf->jdate('H:i:s d-m-Y', strtotime($data['data']['expire'])) : $jdf->jdate('H:i:s d-m-Y', strtotime($order->expire_at));
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }


//        $configCodeRaw = $code ?? '-';
//        $subUrl = rtrim($panel->sub_address, '/') . $order->sub_id;
//        $configCode = htmlspecialchars($configCodeRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
//        $subUrlSafe = htmlspecialchars($subUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message = "<b>✅ جزئیات سفارش #{$order->id}</b>\n\n";
        $message .= "<b>حجم کل:</b> {$totalGb} گیگ\n";
        $message .= "<b>حجم مصرف شده:</b> {$totalUsed} گیگ\n";
        $message .= "<b>حجم باقی مانده:</b> {$left} گیگ\n";
        $message .= "<b>زمان پایان:</b> {$expireTime}\n\n";

        $message .= "<b>✅اطلاعات کاربر</b>\n\n";
        $message .= "<b>نام کاربری:</b> {$targetUser->username}\n";
        $message .= "<b>آیدی تلگرام:</b> {$targetUser->tel_id}\n";

        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons,
            ]),
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminOrderChangeBw($data)
    {
        $id = $data['id'] ?? null;
        $order = Orders::find($id);

        $user = $this->user;
        $telDetail = $user->tel_detail;
        $telDetail['order-bw'] = $order->id;
        $user->tel_detail = $telDetail;
        $user->save();

        $data = getConfigDetail($order);

        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $totalUsed = $data['data']['totalUsed'];
            $left = $data['data']['left'];
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }

        $this->updatePath('adminOrderChangeBwSubmit');

        $message = "<b>تغییر حجم</b>\n\n";
        $message .= "<b>حجم کل:</b> {$totalGb}\n";
        $message .= "<b>حجم مصرف شده:</b> {$totalUsed}\n";
        $message .= "<b>حجم باقی مانده:</b> {$left}\n";

        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 جزئیات سفارش',
                            'callback_data' => "type=adminOrderSingle|id={$order->id}",
                        ]
                    ]
                ]
            ]),
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');

    }

    protected function adminOrderChangeBwSubmit($data)
    {
        $text = intval($data['text']);
        $user = $this->user;
        $order = Orders::find($user->tel_detail['order-bw']);
        $panel = Panels::find($order->panel_id);

        $data = getConfigDetail($order);

        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $totalUsed = $data['data']['totalUsed'];
            $left = $data['data']['left'];
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }


        if ($text >= 0) {
            $totalGb = $totalGb + $text;
            $txtType = ' عملیات افزایش حجم';
        } else {
            $value = str_replace('-', '', $text);
            $totalGb = $totalGb - $value;
            $txtType = ' عملیات کاهش حجم';
        }

        if ($panel->system_type == 'pasarguard') {
            $pasarGuard = new PasarGuard([
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ]);
            if (!$pasarGuard->checkConnection()) {
                return [
                    'status' => false,
                    'message' => $pasarGuard->getLoginStatus()['message'],
                ];
            }
            $result = $pasarGuard->getUserById($order->uid);

            $expire = Carbon::parse($result['expire'])->format('Y-m-d H:i:s');
            $band = gbToByte($totalGb);
            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => $band,
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);

            if ($result['status'] != false) {
                $caption = "$txtType با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');

            } else {
                $caption = "خطا در انجام عملیات";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }


        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($totalGb);


            $expiryTimestamp = $clientData['expiryTime'];
            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $band,
            ];

            $result = updateClient($result);
            if ($result['success']) {
                $caption = "$txtType با موفقیت انجام شد.";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            } else {
                $caption = "خطا در انجام عملیات";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }
        }
    }

    protected function adminOrderChangeTime($data)
    {
        $id = $data['id'] ?? null;

        $order = Orders::find($id);

        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }

        $jdf = new Jdf();

        $user = $this->user;
        $telDetail = $user->tel_detail ?? [];
        $telDetail['order-time'] = $order->id;
        $user->tel_detail = $telDetail;
        $user->save();

        $configDetail = getConfigDetail($order);

        if (!($configDetail['status'] ?? false)) {
            return $this->sendTemporaryMessage($configDetail['msg'] ?? 'دریافت اطلاعات سرویس با خطا مواجه شد.');
        }

        $expire = !empty($configDetail['data']['code'])
            ? ($configDetail['data']['expire'] ?? null)
            : $order->expire_at;

        $expireTime = $expire
            ? $jdf->jdate('H:i:s d-m-Y', strtotime($expire))
            : 'نامشخص';

        $this->updatePath('adminOrderChangeTimeSubmit');

        $message = "⏳ <b>تغییر زمان سرویس</b>\n\n";
        $message .= "📦 <b>شماره سفارش:</b> <code>{$order->id}</code>\n";
        $message .= "📅 <b>تاریخ اتمام فعلی:</b> <code>{$expireTime}</code>\n\n";
        $message .= "برای تغییر زمان سرویس، تعداد روز مورد نظر را ارسال کنید.\n\n";
        $message .= "✅ <b>افزایش زمان:</b>\n";
        $message .= "مثلا <code>10</code> یعنی ۱۰ روز به سرویس اضافه شود.\n\n";
        $message .= "➖ <b>کاهش زمان:</b>\n";
        $message .= "مثلا <code>-10</code> یعنی ۱۰ روز از سرویس کم شود.\n\n";
        $message .= "لطفا فقط عدد را ارسال کنید.";

        $this->updatePath('adminOrderChangeTimeSubmit');
        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 جزئیات سفارش',
                            'callback_data' => "type=adminOrderSingle|id={$order->id}",
                        ],
                    ],
                ],
            ]),
        ], 'message');
    }

    protected function adminOrderChangeTimeSubmit($data)
    {
        $text = intval($data['text']);
        $user = $this->user;
        $order = Orders::find($user->tel_detail['order-bw']);
        $panel = Panels::find($order->panel_id);

        $data = getConfigDetail($order);


        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $expire = $data['data']['expire'];

        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }


        $expire = Carbon::parse($expire);
        if ($text >= 0) {
            $expire->addDays((int)$text);
            $txtType = 'عملیات افزایش زمان';
        } else {
            $expire->subDays(abs((int)$text));
            $txtType = 'عملیات کاهش زمان';
        }

        if ($panel->system_type == 'pasarguard') {
            $pasarGuard = new PasarGuard([
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ]);
            if (!$pasarGuard->checkConnection()) {
                return [
                    'status' => false,
                    'message' => $pasarGuard->getLoginStatus()['message'],
                ];
            }
            $result = $pasarGuard->getUserById($order->uid);

            $expire = $expire->format('Y-m-d H:i:s');
            $band = gbToByte($totalGb);
            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => $band,
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);

            if ($result['status'] != false) {
                $caption = "$txtType با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');

            } else {
                $caption = "خطا در انجام عملیات";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }


        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($totalGb);


            $expiryTimestamp = $clientData['expiryTime'];
            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $band,
            ];

            $result = updateClient($result);
            if ($result['success']) {
                $caption = "$txtType با موفقیت انجام شد.";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            } else {
                $caption = "خطا در انجام عملیات";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }
        }
    }

    /**
     * Admin Area
     */
    // Default Values
}
