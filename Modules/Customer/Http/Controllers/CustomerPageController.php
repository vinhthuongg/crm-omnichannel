<?php

namespace Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CrmNavigationService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Customer\Services\CustomerPageQueryService;

class CustomerPageController extends Controller
{
    /** Nhận CustomerPageQueryService để truy vấn dữ liệu; CrmNavigationService để tạo menu phù hợp với quyền người dùng. */
    public function __construct(private readonly CustomerPageQueryService $queries, private readonly CrmNavigationService $navigation) {}

    /** Kiểm tra quyền quản lý rồi hiển thị trang khách hàng theo bộ lọc request. */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('user.manage'), 403);
        return view('customers.index', [...$this->queries->execute($user, $request->query()),
            'currentUser' => $user, 'navItems' => $this->navigation->forUser($user),
            'sidebar' => ['team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox']]);
    }
}
