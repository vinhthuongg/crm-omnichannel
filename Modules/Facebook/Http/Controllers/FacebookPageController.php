<?php

namespace Modules\Facebook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Facebook\Actions\ConnectFacebookPageAction;
use Modules\Facebook\Actions\ImportFacebookPageMessagesAction;
use Modules\Facebook\Actions\ListFacebookPagesAction;
use Modules\Facebook\DTO\FacebookPageData;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;

class FacebookPageController extends Controller
{
    public function index(Request $request, ListFacebookPagesAction $action, FacebookPageRepository $repository): View|JsonResponse
    {
        try {
            $availablePages = $this->availablePages($action->execute($request->user()));
        } catch (\Throwable $exception) {
            $availablePages = $this->availablePages([]);
            session()->flash('facebook_pages_error', $exception->getMessage());
        }
        $connectedPages = $repository->forUser($request->user())->keyBy('page_id');
        session([
            'facebook_available_pages' => collect($availablePages)
                ->mapWithKeys(fn (FacebookPageData $page): array => [
                    $page->id => [
                        'page_id' => $page->id,
                        'page_name' => $page->name,
                        'page_access_token' => $page->accessToken,
                        'page_avatar' => $page->avatar,
                    ],
                ])
                ->all(),
        ]);

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
            'navItems' => $this->navItems($request->user()),
            'sidebar' => [
                'team_name' => $request->user()->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'availablePages' => $availablePages,
            'connectedPages' => $connectedPages,
        ]);
    }

    public function connect(Request $request, ConnectFacebookPageAction $action): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'string'],
        ]);
        $pagePayload = session('facebook_available_pages.'.$validated['page_id']);

        abort_unless(is_array($pagePayload), 422, 'Fanpage is not available in current Facebook session.');

        try {
            $page = $action->execute($request->user(), new FacebookPageData(
                $pagePayload['page_id'],
                $pagePayload['page_name'],
                $pagePayload['page_access_token'],
                $pagePayload['page_avatar'] ?? null,
            ));
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

        try {
            $syncStats = app(ImportFacebookPageMessagesAction::class)->execute($request->user(), $page, 10);
        } catch (\Throwable $exception) {
            $syncStats = null;
            report($exception);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'page_id' => $page->page_id,
                    'page_name' => $page->page_name,
                    'page_avatar' => $page->page_avatar,
                    'sync' => $syncStats,
                ],
            ], 201);
        }

        $status = $syncStats
            ? "Facebook page connected. Synced {$syncStats['messages']} messages."
            : 'Facebook page connected. Khong dong bo duoc lich su tin nhan, hay thu nut Dong bo tin nhan.';

        return redirect()->route('crm.conversations')->with('status', $status);
    }

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

    /**
     * @param array<int, FacebookPageData> $configuredPages
     * @return array<int, FacebookPageData>
     */
    private function availablePages(array $configuredPages): array
    {
        $pages = collect($configuredPages)->keyBy(fn (FacebookPageData $page): string => $page->id);

        foreach ((array) session('facebook_available_pages', []) as $payload) {
            if (! is_array($payload) || empty($payload['page_id']) || empty($payload['page_access_token'])) {
                continue;
            }

            $page = new FacebookPageData(
                (string) $payload['page_id'],
                (string) ($payload['page_name'] ?? $payload['page_id']),
                (string) $payload['page_access_token'],
                $payload['page_avatar'] ?? null,
            );

            $pages->put($page->id, $page);
        }

        return $pages->values()->all();
    }

    private function navItems(User $user): array
    {
        $items = [
            ['section' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'D'],
            ['section' => 'conversations', 'label' => 'Conversations', 'route' => 'crm.conversations', 'icon' => 'C'],
            ['section' => 'customers', 'label' => 'Customers', 'route' => 'crm.customers', 'icon' => 'K'],
            ['section' => 'agents', 'label' => 'Agents', 'route' => 'crm.agents', 'icon' => 'A'],
            ['section' => 'channels', 'label' => 'Channels', 'route' => 'crm.channels', 'icon' => 'O'],
            ['section' => 'reports', 'label' => 'Reports', 'route' => 'crm.reports', 'icon' => 'R'],
            ['section' => 'activity', 'label' => 'Activity Log', 'route' => 'crm.activity', 'icon' => 'L'],
            ['section' => 'notifications', 'label' => 'Notifications', 'route' => 'crm.notifications', 'icon' => 'N'],
            ['section' => 'settings', 'label' => 'Settings', 'route' => 'crm.settings', 'icon' => 'S'],
        ];

        if ($user->can('user.manage')) {
            array_splice($items, 4, 0, [[
                'section' => 'work_shifts',
                'label' => 'Shifts',
                'route' => 'work-shifts.index',
                'icon' => 'T',
            ]]);
        }

        return $items;
    }
}
