<?php

namespace Modules\Mobile\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Modules\Conversation\Models\Tag;
use Modules\Mobile\Services\MobileBootstrapQueryService;
use Modules\Shared\Http\Controllers\ApiController;

class MobileBootstrapController extends ApiController
{
    /** Nhận query service cung cấp nhân viên và nhãn dùng khi khởi động ứng dụng mobile. */
    public function __construct(private readonly MobileBootstrapQueryService $queries) {}

    /** Trả về hồ sơ, vai trò và quyền của người dùng mobile đang đăng nhập. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    /** Cung cấp một lần dữ liệu khởi động gồm người dùng, quyền, nhân viên, nhãn, kênh và cấu hình realtime. */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->queries->data();

        return response()->json(['data' => [
            'user' => $this->userPayload($user),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'agents' => $data['agents']
                ->map(fn (User $agent): array => $this->agentPayload($agent))->values(),
            'tags' => $data['tags']
                ->map(fn (Tag $tag): array => $this->tagPayload($tag))->values(),
            'channels' => ['facebook', 'zalo'],
            'realtime' => $this->realtimePayload($request),
        ]]);
    }

    /** Xác thực socket được phép đăng ký private channel realtime đã yêu cầu. */
    public function broadcastAuth(Request $request)
    {
        $request->validate(['socket_id' => ['required', 'string'], 'channel_name' => ['required', 'string']]);

        return Broadcast::auth($request);
    }

    /** Chuyển nhân viên thành ID, tên, email, trạng thái hoạt động và danh sách vai trò. */
    private function agentPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values() : $user->getRoleNames()->values(),
        ];
    }

    /** Mở rộng payload nhân viên hiện tại với toàn bộ quyền được cấp. */
    private function userPayload(User $user): array
    {
        return [...$this->agentPayload($user->loadMissing('roles')), 'permissions' => $user->getAllPermissions()->pluck('name')->values()];
    }

    /** Chuyển nhãn thành dữ liệu ID, tên, màu và trạng thái mặc định cho mobile. */
    private function tagPayload(Tag $tag): array
    {
        return ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color, 'is_default' => (bool) $tag->is_default];
    }

    /** Tạo URL WebSocket/SSE, private channel và tên event từ cấu hình Reverb hiện hành. */
    private function realtimePayload(Request $request): array
    {
        $reverb = config('broadcasting.connections.reverb');
        $publicHost = config('reverb.public.host');
        $publicPort = config('reverb.public.port');
        $publicScheme = match (config('reverb.public.scheme')) {
            'http' => 'ws',
            'https' => 'wss',
            default => config('reverb.public.scheme'),
        };
        $host = $publicHost ?: (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) ? $request->getHost() : $reverb['options']['host']);
        $port = $publicHost ? $publicPort : (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) && $request->secure() ? null : $reverb['options']['port']);
        $scheme = $publicScheme ?: ($request->secure() ? 'wss' : ($reverb['options']['scheme'] === 'https' ? 'wss' : 'ws'));

        return [
            'enabled' => filled($reverb['key']) && filled($host),
            'key' => $reverb['key'],
            'websocket_url' => sprintf('%s://%s%s/app/%s?protocol=7&client=crm-mobile&version=1.0&flash=false', $scheme, $host, $port ? ':'.(int) $port : '', rawurlencode((string) $reverb['key'])),
            'auth_url' => url('/api/mobile/broadcasting/auth'),
            'inbox_stream_url' => url('/api/mobile/conversations/stream'),
            'conversation_stream_url_pattern' => url('/api/mobile/conversations/{conversation_id}/messages/stream'),
            'inbox_channel' => 'private-crm.user.'.$request->user()->id.'.conversations',
            'conversation_channel_pattern' => 'private-crm.conversation.{conversation_id}',
            'events' => ['message.created', 'message.updated', 'message.deleted'],
        ];
    }
}
