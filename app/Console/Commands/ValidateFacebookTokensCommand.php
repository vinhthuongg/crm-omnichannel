<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookTokenValidationService;

class ValidateFacebookTokensCommand extends Command
{
    protected $signature = 'facebook:validate-tokens';

    protected $description = 'Validate all connected Facebook page access tokens.';

    /** Kiểm tra token của từng Facebook Page, cập nhật trạng thái hợp lệ và in kết quả. */
    public function handle(FacebookTokenValidationService $tokens, FacebookPageRepository $pages): int
    {
        $valid = 0;
        $invalid = 0;

        FacebookPage::query()
            ->orderBy('id')
            ->chunkById(100, function ($facebookPages) use ($tokens, $pages, &$valid, &$invalid): void {
                foreach ($facebookPages as $page) {
                    try {
                        $tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                        $debugToken = $tokens->validatePageToken($page->page_access_token);
                        $pages->markValid($page, $debugToken);
                        $valid++;

                        $this->line("VALID page_id={$page->page_id} page_name=\"{$page->page_name}\"");
                    } catch (\Throwable $exception) {
                        $pages->markInvalid($page, $exception->getMessage());
                        $invalid++;

                        $this->error("INVALID page_id={$page->page_id} page_name=\"{$page->page_name}\" error=\"{$exception->getMessage()}\"");
                    }
                }
            });

        $this->info("Facebook token validation complete. valid={$valid}, invalid={$invalid}");

        return $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }
}
