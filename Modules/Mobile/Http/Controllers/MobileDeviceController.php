<?php

namespace Modules\Mobile\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Services\FcmDeviceTokenService;
use Modules\Shared\Http\Controllers\ApiController;

class MobileDeviceController extends ApiController
{
    /** Nhận dịch vụ đăng ký và thu hồi FCM token của thiết bị mobile. */
    public function __construct(private readonly FcmDeviceTokenService $tokens) {}

    /** Lưu hoặc cập nhật FCM token cùng nền tảng, thiết bị và phiên bản ứng dụng của người dùng. */
    public function storeFcmToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:255'],
        ]);

        $token = $this->tokens->store($request->user(), $data);

        return response()->json([
            'data' => [
                'id' => (int) $token->id,
                'platform' => $token->platform,
                'device_id' => $token->device_id,
                'app_version' => $token->app_version,
                'last_used_at' => $token->last_used_at?->toISOString(),
            ],
        ]);
    }

    /** Gỡ FCM token khỏi tài khoản để thiết bị không còn nhận push notification. */
    public function destroyFcmToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $this->tokens->delete($request->user(), $data['token']);

        return response()->json(status: 204);
    }
}
