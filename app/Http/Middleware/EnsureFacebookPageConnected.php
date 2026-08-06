<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookOAuthService;
use Modules\Facebook\Services\FacebookTokenValidationService;
use Symfony\Component\HttpFoundation\Response;

class EnsureFacebookPageConnected
{
    /** Nhận FacebookPageRepository để đọc và lưu dữ liệu; FacebookTokenValidationService để kiểm tra token còn hiệu lực và đúng Facebook App; FacebookOAuthService để tạo URL đăng nhập, đổi code và lấy danh sách Page. */
    public function __construct(
        private readonly FacebookPageRepository $pages,
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookOAuthService $facebook,
    ) {
    }

    /** Kiểm tra Page trong route còn kết nối/token hợp lệ trước khi cho request tiếp tục. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->can('conversation.view_all')) {
            return $next($request);
        }

        $routePage = $request->route('facebookPage');

        if ($routePage instanceof FacebookPage) {
            abort_unless((int) $routePage->user_id === (int) $user->id, 403);
            $this->ensureValidToken($routePage);

            return $next($request);
        }

        $connectedPages = FacebookPage::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        if ($connectedPages->isEmpty()) {
            return $this->missingPageResponse($request);
        }

        foreach ($connectedPages as $page) {
            try {
                $this->ensureValidToken($page);

                return $next($request);
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->invalidTokenResponse($request);
    }

    /** Kiểm tra kết nối Facebook Page và tạo phản hồi lỗi tại bước ensureValidToken. */
    private function ensureValidToken(FacebookPage $page): void
    {
        $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);

        if ($page->token_status === 'invalid') {
            throw new \RuntimeException('Facebook page token is invalid.');
        }

        if ($page->token_expires_at && $page->token_expires_at->isPast()) {
            $this->pages->markInvalid($page, 'Facebook page token has expired.');
            throw new \RuntimeException('Facebook page token has expired.');
        }

        $debugToken = $this->tokens->validatePageToken($page->page_access_token);
        $this->pages->markValid($page, $debugToken);

        if (! $page->subscribed_at) {
            $this->facebook->subscribePage($page->page_id, $page->page_access_token);
            $page->forceFill(['subscribed_at' => now()])->save();
        }
    }

    /** Kiểm tra kết nối Facebook Page và tạo phản hồi lỗi tại bước missingPageResponse. */
    private function missingPageResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Chua ket noi fanpage Facebook.',
            ], 409);
        }

        return redirect()->route('facebook.pages')->withErrors([
            'facebook' => 'Hay ket noi fanpage Facebook truoc khi mo Messenger.',
        ]);
    }

    /** Kiểm tra kết nối Facebook Page và tạo phản hồi lỗi tại bước invalidTokenResponse. */
    private function invalidTokenResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Facebook page token khong hop le. Vui long ket noi lai fanpage.',
            ], 409);
        }

        return redirect()->route('facebook.pages')->withErrors([
            'facebook' => 'Facebook page token khong hop le. Vui long ket noi lai fanpage.',
        ]);
    }
}
