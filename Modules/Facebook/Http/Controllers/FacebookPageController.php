<?php

namespace Modules\Facebook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CrmNavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Facebook\Actions\ConnectFacebookPageAction;
use Modules\Facebook\Actions\DisconnectFacebookPageAction;
use Modules\Facebook\Actions\ImportFacebookPageMessagesAction;
use Modules\Facebook\Actions\ListFacebookPagesAction;
use Modules\Facebook\DTO\FacebookPageData;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookAvailablePageSession;

class FacebookPageController extends Controller
{
    /** Nhận CrmNavigationService để tạo menu phù hợp với quyền người dùng; FacebookAvailablePageSession để lưu và lấy Page người dùng vừa cấp quyền. */
    public function __construct(private readonly CrmNavigationService $navigation, private readonly FacebookAvailablePageSession $availablePageSession)
    {
    }

    /** Liệt kê Page có thể kết nối và Page đã kết nối dưới dạng HTML hoặc JSON. */
    public function index(Request $request, ListFacebookPagesAction $action, FacebookPageRepository $repository): View|JsonResponse
    {
        try {
            $availablePages = $this->availablePageSession->merge($action->execute($request->user()));
        } catch (\Throwable $exception) {
            $availablePages = $this->availablePageSession->merge([]);
            session()->flash('facebook_pages_error', $exception->getMessage());
        }
        $connectedPages = $repository->forUser($request->user())->keyBy('page_id');
        $this->availablePageSession->store($availablePages);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => array_map(fn (FacebookPageData $page): array => [
                    'page_id' => $page->id,
                    'page_name' => $page->name,
                    'page_avatar' => $page->avatar,
                    'connected' => $connectedPages->has($page->id),
                ], $availablePages),
            ]);
        }

        return view('facebook_pages', [
            'currentUser' => $request->user(),
            'activeSection' => 'channels',
            'navItems' => $this->navigation->forUser($request->user()),
            'sidebar' => [
                'team_name' => $request->user()->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'availablePages' => $availablePages,
            'connectedPages' => $connectedPages,
        ]);
    }

    /** Lấy Page đã chọn từ session OAuth, kết nối Page và lưu token/webhook. */
    public function connect(Request $request, ConnectFacebookPageAction $action): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'string'],
        ]);
        try {
            $page = $action->execute($request->user(), $this->availablePageSession->get($validated['page_id']));
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return redirect()->route('facebook.pages')->withErrors([
                'facebook' => $exception->getMessage(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'page_id' => $page->page_id,
                    'page_name' => $page->page_name,
                    'page_avatar' => $page->page_avatar,
                    'sync' => null,
                ],
            ], 201);
        }

        return redirect()
            ->route('crm.conversations')
            ->with('status', 'Facebook page connected. Lich su tin nhan se khong duoc dong bo tu dong.');
    }

    /** Nhập lịch sử tin nhắn của Page được kết nối và trả thống kê đồng bộ. */
    public function sync(Request $request, FacebookPage $facebookPage, ImportFacebookPageMessagesAction $action): RedirectResponse|JsonResponse
    {
        try {
            $limit = (int) $request->integer('limit', 20);
            $stats = $action->execute($request->user(), $facebookPage, min(max($limit, 1), 50));
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return redirect()->route('facebook.pages')->withErrors([
                'facebook' => $exception->getMessage(),
            ]);
        }

        if (! $request->expectsJson()) {
            return redirect()->route('crm.conversations')->with('status', "Synced {$stats['messages']} messages.");
        }

        return response()->json(['data' => $stats]);
    }

    /** Hủy webhook và xóa kết nối Facebook Page, nhưng giữ nguyên khách hàng, hội thoại và tin nhắn đã nhập. */
    public function destroy(Request $request, FacebookPage $facebookPage, DisconnectFacebookPageAction $action): RedirectResponse|JsonResponse
    {
        $pageName = $facebookPage->page_name;
        $unsubscribed = $action->execute($facebookPage);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'disconnected' => true,
                    'facebook_unsubscribed' => $unsubscribed,
                ],
            ]);
        }

        $message = $unsubscribed
            ? "Đã xóa kết nối Facebook Page {$pageName}."
            : "Đã xóa kết nối {$pageName} khỏi CRM; Facebook không thể hủy webhook do token đã hết hiệu lực.";

        return redirect()->route('crm.channels')->with('channels_status', $message);
    }

}
