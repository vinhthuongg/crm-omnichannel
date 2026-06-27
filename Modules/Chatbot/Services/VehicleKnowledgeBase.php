<?php

namespace Modules\Chatbot\Services;

class VehicleKnowledgeBase
{
    public function documents(): array
    {
        return array_values(array_merge(
            $this->priceDocuments(),
            $this->promotionDocuments(),
            $this->installmentDocuments(),
            $this->bankLoanProcessDocuments(),
            $this->firstTimeBuyerDocuments(),
            $this->roadPriceScriptDocuments(),
        ));
    }

    public function contextDocuments(string $query, ?string $topic = null): array
    {
        $documents = collect($this->documents());
        $matchedModels = collect($this->detectModels($query));
        $processes = collect($this->bankLoanProcessDocuments())
            ->filter(fn (array $document): bool => $this->matchesBankLoanProcess($query, $document))
            ->take(6);
        $firstTimeBuyerScripts = collect($this->firstTimeBuyerDocuments())
            ->filter(fn (array $document): bool => $this->matchesFirstTimeBuyerScript($query, $document))
            ->take(5);
        $roadPriceScripts = collect($this->roadPriceScriptDocuments())
            ->filter(fn (array $document): bool => $this->matchesRoadPriceScript($query, $document))
            ->take(5);

        if ($matchedModels->isEmpty()) {
            return $topic === 'INSTALLMENT_LOAN' || $topic === 'PRICE_BY_AREA' || $processes->isNotEmpty() || $firstTimeBuyerScripts->isNotEmpty() || $roadPriceScripts->isNotEmpty()
                ? $processes->merge($firstTimeBuyerScripts)->merge($roadPriceScripts)->values()->all()
                : [];
        }

        $matched = $documents->filter(function (array $document) use ($matchedModels): bool {
            return $matchedModels->contains((string) data_get($document, 'metadata.model'));
        });

        $prices = $matched
            ->where('source', 'giaxe_json')
            ->unique(fn (array $document): string => implode('|', [
                data_get($document, 'metadata.model'),
                data_get($document, 'metadata.grade'),
                data_get($document, 'metadata.price'),
            ]))
            ->take(10);

        $promotions = $matched
            ->where('source', 'ctkm_json')
            ->take(8);

        $installments = $matched
            ->where('source', 'ctrinh_tragop')
            ->take(8);

        return match ($topic) {
            'PROMOTIONS' => $promotions->merge($prices)->values()->all(),
            'INSTALLMENT_LOAN' => $installments->merge($prices)->merge($promotions)->merge($processes)->values()->all(),
            'PRICE_BY_AREA' => $prices->merge($promotions)->merge($roadPriceScripts)->values()->all(),
            'VERSION_CONSULTING' => $firstTimeBuyerScripts->merge($prices)->merge($promotions)->values()->all(),
            default => $prices->merge($promotions)->merge($firstTimeBuyerScripts)->merge($roadPriceScripts)->values()->all(),
        };
    }

    public function detectModels(string $text): array
    {
        $normalizedText = $this->normalize($text);

        return collect($this->documents())
            ->pluck('metadata.model')
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $model): int => strlen($model))
            ->filter(fn (string $model): bool => str_contains($normalizedText, $this->normalize($model)))
            ->values()
            ->all();
    }

    public function quickReplies(): array
    {
        return [
            ['content_type' => 'text', 'title' => 'Báo giá lăn bánh', 'payload' => 'PRICE_BY_AREA'],
            ['content_type' => 'text', 'title' => 'Ưu đãi hiện hành', 'payload' => 'PROMOTIONS'],
            ['content_type' => 'text', 'title' => 'Vay trả góp', 'payload' => 'INSTALLMENT_LOAN'],
            ['content_type' => 'text', 'title' => 'Tình trạng xe', 'payload' => 'VEHICLE_AVAILABILITY'],
            ['content_type' => 'text', 'title' => 'Chọn phiên bản', 'payload' => 'VERSION_CONSULTING'],
        ];
    }

    public function flow(string $payload): ?array
    {
        return [
            'PRICE_BY_AREA' => [
                'topic' => 'PRICE_BY_AREA',
                'label' => 'Bao gia lan banh',
                'question' => 'Anh/Chị cần em báo giá lăn bánh mẫu xe gì ạ?',
                'search_prefix' => 'giá xe báo giá lăn bánh',
            ],
            'PROMOTIONS' => [
                'topic' => 'PROMOTIONS',
                'label' => 'Uu dai hien hanh',
                'question' => 'Anh/Chị quan tâm mẫu xe nào để em kiểm tra chương trình ưu đãi hiện hành ạ?',
                'search_prefix' => 'khuyến mãi ưu đãi chương trình',
            ],
            'INSTALLMENT_LOAN' => [
                'topic' => 'INSTALLMENT_LOAN',
                'label' => 'Vay tra gop',
                'question' => 'Anh/Chị muốn tư vấn trả góp mẫu xe nào và dự kiến trả trước khoảng bao nhiêu ạ?',
                'search_prefix' => 'vay trả góp giá xe',
            ],
            'VEHICLE_AVAILABILITY' => [
                'topic' => 'VEHICLE_AVAILABILITY',
                'label' => 'Tinh trang xe',
                'question' => 'Anh/Chị muốn kiểm tra tình trạng xe, màu xe và thời gian giao xe của mẫu nào ạ?',
                'search_prefix' => 'màu xe tình trạng xe giao xe',
            ],
            'VERSION_CONSULTING' => [
                'topic' => 'VERSION_CONSULTING',
                'label' => 'Tu van phien ban',
                'question' => 'Anh/Chị đang quan tâm mẫu xe nào và nhu cầu sử dụng chính là gì ạ?',
                'search_prefix' => 'tư vấn phiên bản mẫu xe grade',
            ],
        ][$payload] ?? null;
    }

    public function flowFromText(string $text): ?array
    {
        $normalized = $this->normalize($text);

        $map = [
            'bao gia lan banh' => 'PRICE_BY_AREA',
            'uu dai hien hanh' => 'PROMOTIONS',
            'vay tra gop' => 'INSTALLMENT_LOAN',
            'tinh trang xe' => 'VEHICLE_AVAILABILITY',
            'chon phien ban' => 'VERSION_CONSULTING',
        ];

        foreach ($map as $needle => $payload) {
            if (str_contains($normalized, $needle)) {
                return $this->flow($payload);
            }
        }

        return null;
    }

    private function priceDocuments(): array
    {
        return collect($this->json('giaxe_json.txt'))
            ->map(function (array $row): array {
                $model = $this->value($row, 'model');
                $grade = $this->value($row, 'grade');
                $color = $this->value($row, 'mau_xe');
                $price = $this->value($row, 'gia_ban_le_niem_yet');

                return [
                    'id' => 'price:'.md5(json_encode($row)),
                    'source' => 'giaxe_json',
                    'metadata' => [
                        'model' => $model,
                        'grade' => $grade,
                        'color' => $color,
                        'price' => $price,
                    ],
                    'text' => trim("Bảng giá xe Toyota Kiên Giang: {$model} {$grade}, màu {$color}, mã màu ".$this->value($row, 'ma_mau').", giá bán lẻ niêm yết {$price} đồng. CKD/CBU: ".$this->value($row, 'ckd_cbu').'.'),
                ];
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function promotionDocuments(): array
    {
        $payload = $this->json('ctkm_json.txt');
        $program = $this->value($payload, 'ten_chuong_trinh');
        $updatedAt = $this->value($payload, 'ngay_cap_nhat');

        return collect((array) data_get($payload, 'danh_sach_khuyen_mai', []))
            ->map(function (array $row) use ($program, $updatedAt): array {
                $model = $this->value($row, 'dong_xe');
                $grade = $this->value($row, 'ma_ban');
                $discount = (int) ($row['giam_gia_tien_mat'] ?? 0);
                $insurance = $this->value($row, 'bao_hiem') ?: 'không có thông tin bảo hiểm';
                $gifts = implode(', ', array_filter((array) ($row['qua_tang_phu_kien'] ?? [])));
                $note = $this->value($row, 'ghi_chu');

                return [
                    'id' => 'promotion:'.md5(json_encode($row)),
                    'source' => 'ctkm_json',
                    'metadata' => [
                        'program' => $program,
                        'updated_at' => $updatedAt,
                        'model' => $model,
                        'grade' => $grade,
                        'discount' => $discount,
                    ],
                    'text' => trim("Chương trình khuyến mãi Toyota Kiên Giang {$program}, cập nhật {$updatedAt}: {$model} {$grade}, giảm giá tiền mặt ".number_format($discount, 0, ',', '.').' đồng, bảo hiểm '.$insurance.', quà tặng phụ kiện '.($gifts ?: 'không có thông tin').($note ? ', ghi chú '.$note : '').'.'),
                ];
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function installmentDocuments(): array
    {
        $payload = $this->json('ctrinh_tragop.txt');
        $program = $this->value($payload, 'ten_chuong_trinh');
        $period = $this->value($payload, 'thoi_gian_ap_dung');

        return collect((array) data_get($payload, 'cac_goi_uu_dai', []))
            ->flatMap(function (array $row) use ($program, $period): array {
                $package = $this->value($row, 'ten_goi');
                $product = $this->value($row, 'san_pham');
                $phaseOne = $this->value((array) data_get($row, 'lai_suat', []), 'giai_doan_1');
                $phaseTwo = $this->value((array) data_get($row, 'lai_suat', []), 'giai_doan_2');
                $customer = $this->value((array) data_get($row, 'dieu_kien', []), 'doi_tuong');
                $minMonths = (string) (
                    data_get($row, 'dieu_kien.thoi_gian_vay_toi_thieu_thang')
                    ?: data_get($row, 'dieu_kien.thoi_gian_vay_ap_dung_thang')
                    ?: ''
                );

                return collect((array) data_get($row, 'mau_xe_ap_dung', []))
                    ->map(function (string $model) use ($row, $program, $period, $package, $product, $phaseOne, $phaseTwo, $customer, $minMonths): array {
                        $text = trim(implode(' ', array_filter([
                            "Chuong trinh tra gop Toyota Kien Giang {$program}.",
                            $period,
                            "Mau xe ap dung: {$model}.",
                            "Goi uu dai: {$package}.",
                            "San pham vay: {$product}.",
                            $phaseOne !== '' ? "Lai suat giai doan 1: {$phaseOne}." : '',
                            $phaseTwo !== '' ? "Lai suat giai doan 2: {$phaseTwo}." : '',
                            $customer !== '' ? "Doi tuong/dieu kien: {$customer}." : '',
                            $minMonths !== '' ? "Thoi gian vay: {$minMonths} thang." : '',
                        ])));

                        return [
                            'id' => 'installment:'.md5(json_encode($row).$model),
                            'source' => 'ctrinh_tragop',
                            'metadata' => [
                                'program' => $program,
                                'period' => $period,
                                'model' => $model,
                                'package' => $package,
                                'product' => $product,
                                'phase_one' => $phaseOne,
                                'phase_two' => $phaseTwo,
                                'customer' => $customer,
                                'months' => $minMonths,
                            ],
                            'text' => $text,
                        ];
                    })
                    ->all();
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function bankLoanProcessDocuments(): array
    {
        $payload = $this->json('quytrinh_vay_nganhang.txt');
        $processName = $this->value($payload, 'ten_quy_trinh');

        return collect((array) data_get($payload, 'cac_buoc_quy_trinh', []))
            ->map(function (array $row) use ($processName): array {
                $step = (string) ($row['buoc'] ?? '');
                $title = $this->value($row, 'tieu_de');
                $answer = $this->value($row, 'cau_tra_loi_nhan_vien');

                $parts = [
                    $processName,
                    "Bước {$step}: {$title}.",
                    $this->value($row, 'cau_hoi_khach_hang'),
                    $answer,
                    $this->listText((array) ($row['cau_hoi_lam_ro_nhu_cau'] ?? []), 'Câu hỏi làm rõ'),
                    $this->listText((array) ($row['cac_buoc_thuc_hien'] ?? []), 'Quy trình'),
                    $this->value($row, 'ghi_chu_nhan_vien'),
                    $this->value($row, 'cau_hoi_goi_mo'),
                    $this->loanDocumentGroups((array) ($row['nhom_giay_to'] ?? [])),
                    $this->value($row, 'ghi_chu_nop_ho_so'),
                    $this->estimateText((array) ($row['phuong_an_uoc_tinh'] ?? [])),
                    $this->value($row, 'hanh_dong_tiep_theo'),
                    $this->listText((array) ($row['tom_tat_quy_trinh_nhanh'] ?? []), 'Tóm tắt nhanh'),
                ];

                return [
                    'id' => 'bank-loan-process:'.$step,
                    'source' => 'quytrinh_vay_nganhang',
                    'metadata' => [
                        'step' => $step,
                        'title' => $title,
                        'answer' => $answer,
                        'clarifying_questions' => (array) ($row['cau_hoi_lam_ro_nhu_cau'] ?? []),
                        'process_steps' => (array) ($row['cac_buoc_thuc_hien'] ?? []),
                        'loan_conditions' => (array) ($row['dieu_kien_vay'] ?? []),
                        'document_groups' => (array) ($row['nhom_giay_to'] ?? []),
                        'quick_summary' => (array) ($row['tom_tat_quy_trinh_nhanh'] ?? []),
                        'follow_up' => (string) (
                            $row['cau_hoi_goi_mo']
                            ?? $row['ghi_chu_nhan_vien']
                            ?? $row['ghi_chu_nop_ho_so']
                            ?? $row['hanh_dong_tiep_theo']
                            ?? ''
                        ),
                    ],
                    'text' => trim(implode(' ', array_filter($parts))),
                ];
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function matchesBankLoanProcess(string $query, array $document): bool
    {
        $normalizedQuery = $this->normalize($query);
        $normalizedText = $this->normalize((string) ($document['text'] ?? ''));
        $keywords = [
            'vay',
            'tra gop',
            'gop',
            'ngan hang',
            'ho so',
            'giay to',
            'dieu kien',
            'duyet',
            'xet duyet',
            'vpbank',
            'mb',
            'mb bank',
            'tpbank',
            'bidv',
            'tfs',
            'toyota finance',
            'tra truoc',
            'tai chinh',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedQuery, $keyword)) {
                return true;
            }
        }

        return collect(explode(' ', $normalizedQuery))
            ->filter(fn (string $word): bool => strlen($word) >= 4)
            ->contains(fn (string $word): bool => str_contains($normalizedText, $word));
    }

    private function firstTimeBuyerDocuments(): array
    {
        $payload = $this->json('format_mua_xe_lan_dau.txt');
        $formatName = $this->value($payload, 'ten_format');
        $goal = $this->value($payload, 'muc_tieu');
        $situations = (array) data_get($payload, 'kich_ban_chi_tiet', []);
        $documents = collect($situations)
            ->flatMap(function (array $situation, string $key) use ($formatName, $goal): array {
                $situationName = $this->value($situation, 'ten_tinh_huong');

                return collect((array) data_get($situation, 'cac_buoc', []))
                    ->map(function (array $row) use ($formatName, $goal, $key, $situationName): array {
                        $step = (string) ($row['buoc'] ?? '');
                        $title = $this->value($row, 'ten_buoc');
                        $questions = (array) ($row['cau_hoi_khai_thac_nhu_cau'] ?? []);
                        $suggestions = (array) ($row['goi_y_san_pham'] ?? []);
                        $qa = (array) ($row['hoi_dap'] ?? $row['Hoi_Dap'] ?? []);

                        $parts = [
                            $formatName,
                            $goal,
                            $situationName,
                            "Buoc {$step}: {$title}.",
                            $this->value($row, 'loi_thoai_nhan_vien_1'),
                            $this->value($row, 'loi_thoai_nhan_vien_2'),
                            $this->value($row, 'loi_thoai_nhan_vien'),
                            $this->value($row, 'loi_thoai_khach_hang'),
                            $this->listText($questions, 'Cau hoi khai thac nhu cau'),
                            $this->vehicleSuggestionsText($suggestions),
                            $this->productQaText($qa),
                            $this->value($row, 'ghi_chu'),
                            $this->value($row, 'hanh_dong_tiep_theo'),
                        ];

                        return [
                            'id' => 'first-time-buyer:'.$key.':'.$step,
                            'source' => 'format_mua_xe_lan_dau',
                            'metadata' => [
                                'situation' => $key,
                                'situation_name' => $situationName,
                                'step' => $step,
                                'title' => $title,
                                'staff_opening' => $this->value($row, 'loi_thoai_nhan_vien_1') ?: $this->value($row, 'loi_thoai_nhan_vien'),
                                'staff_follow_up' => $this->value($row, 'loi_thoai_nhan_vien_2'),
                                'need_questions' => $questions,
                                'vehicle_suggestions' => $suggestions,
                                'product_qa' => $qa,
                                'next_action' => $this->value($row, 'hanh_dong_tiep_theo'),
                            ],
                            'text' => trim(implode(' ', array_filter($parts))),
                        ];
                    })
                    ->all();
            });

        $commonQuestions = (array) data_get($payload, 'bo_cau_hoi_goi_y.danh_sach_cau_hoi', []);
        if ($commonQuestions !== []) {
            $documents->push([
                'id' => 'first-time-buyer:common-product-questions',
                'source' => 'format_mua_xe_lan_dau',
                'metadata' => [
                    'situation' => 'common_questions',
                    'situation_name' => 'Cac cau hoi ve san pham thuong gap',
                    'step' => 'common',
                    'title' => 'Cac cau hoi ve san pham thuong gap',
                    'need_questions' => [],
                    'vehicle_suggestions' => [],
                    'product_qa' => [],
                    'common_questions' => $commonQuestions,
                    'next_action' => '',
                ],
                'text' => 'Cac cau hoi san pham thuong gap: '.implode('; ', array_filter(array_map(fn ($item): string => $this->stringifyValue($item), $commonQuestions))),
            ]);
        }

        return $documents
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function matchesFirstTimeBuyerScript(string $query, array $document): bool
    {
        $normalizedQuery = $this->normalize($query);
        $normalizedText = $this->normalize((string) ($document['text'] ?? ''));
        $keywords = [
            'lan dau',
            'mua xe lan dau',
            'chua biet',
            'khong biet chon xe',
            'tu van tu dau',
            'chon xe nao',
            'di gia dinh',
            'di lam',
            'cho khach',
            'camera',
            'cam bien',
            'man hinh',
            'ghe da',
            'ghe ni',
            'tui khi',
            'abs',
            'phanh',
            'gam cao',
            'tiet kiem xang',
            'cua gio',
            'ban top',
            'ban thuong',
            'khac nhau',
            'so tu dong',
            'so san',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedQuery, $keyword)) {
                return true;
            }
        }

        return collect(explode(' ', $normalizedQuery))
            ->filter(fn (string $word): bool => strlen($word) >= 4)
            ->contains(fn (string $word): bool => str_contains($normalizedText, $word));
    }

    private function roadPriceScriptDocuments(): array
    {
        $payload = $this->json('format_gia_lan_banh.txt');
        $scriptName = $this->value($payload, 'ten_kich_ban');

        return collect((array) data_get($payload, 'cac_tinh_huong', []))
            ->flatMap(function (array $situation) use ($scriptName): array {
                $situationCode = $this->value($situation, 'ma_tinh_huong');
                $situationName = $this->value($situation, 'ten_tinh_huong');
                $popularQuestions = (array) ($situation['cau_hoi_pho_bien'] ?? []);

                return collect((array) data_get($situation, 'cac_buoc_trien_khai', []))
                    ->map(function (array $row) use ($scriptName, $situationCode, $situationName, $popularQuestions): array {
                        $step = (string) ($row['buoc'] ?? '');
                        $title = $this->value($row, 'ten_buoc');
                        $answer = $this->value($row, 'loi_thoai_nhan_vien')
                            ?: $this->value($row, 'loi_thoai_nhan_vien_dan_nhap')
                            ?: $this->value($row, 'loi_thoai_nhan_vien_chot_gia');
                        $questions = (array) ($row['cau_hoi_khai_thac'] ?? []);
                        $costItems = (array) ($row['cac_khoan_chi_phi'] ?? []);
                        $qa = (array) ($row['cac_cap_hoi_dap'] ?? []);
                        $sampleCost = (array) ($row['bang_tinh_mau'] ?? $row['uoc_tinh_chi_phi'] ?? []);

                        $parts = [
                            $scriptName,
                            $situationName,
                            "Buoc {$step}: {$title}.",
                            $this->value($row, 'loi_thoai_khach_hang'),
                            $answer,
                            $this->listText($questions, 'Cau hoi khai thac'),
                            $this->listText($costItems, 'Cac khoan chi phi lan banh'),
                            $this->productQaText($qa),
                            $this->estimateText($sampleCost),
                            $this->value($row, 'loi_thoai_nhan_vien_tiep_tuc'),
                            $this->value($row, 'loi_thoai_nhan_vien_phan_hoi'),
                            $this->listText($popularQuestions, 'Cau hoi pho bien'),
                        ];

                        return [
                            'id' => 'road-price-script:'.$situationCode.':'.$step,
                            'source' => 'format_gia_lan_banh',
                            'metadata' => [
                                'situation' => $situationCode,
                                'situation_name' => $situationName,
                                'step' => $step,
                                'title' => $title,
                                'answer' => $answer,
                                'questions' => $questions,
                                'cost_items' => $costItems,
                                'qa' => $qa,
                                'sample_cost' => $sampleCost,
                                'follow_up' => (string) (
                                    $row['loi_thoai_nhan_vien_chot_gia']
                                    ?? $row['loi_thoai_nhan_vien_tiep_tuc']
                                    ?? $row['loi_thoai_nhan_vien_phan_hoi']
                                    ?? ''
                                ),
                                'popular_questions' => $popularQuestions,
                            ],
                            'text' => trim(implode(' ', array_filter($parts))),
                        ];
                    })
                    ->all();
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function matchesRoadPriceScript(string $query, array $document): bool
    {
        $normalizedQuery = $this->normalize($query);
        $normalizedText = $this->normalize((string) ($document['text'] ?? ''));
        $keywords = [
            'lan banh',
            'gia lan banh',
            'gia niem yet',
            'niem yet',
            'thue truoc ba',
            'truoc ba',
            'phi bien so',
            'dang ky xe',
            'dang kiem',
            'bao tri duong bo',
            'bao hiem vat chat',
            'bao hiem',
            'tra thang',
            'tra gop',
            'phat sinh',
            'chon bien so',
            'coc',
            'giu xe',
            'bao gia nhanh',
            'tron goi',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedQuery, $keyword)) {
                return true;
            }
        }

        return collect(explode(' ', $normalizedQuery))
            ->filter(fn (string $word): bool => strlen($word) >= 4)
            ->contains(fn (string $word): bool => str_contains($normalizedText, $word));
    }

    private function vehicleSuggestionsText(array $suggestions): string
    {
        if ($suggestions === []) {
            return '';
        }

        return collect($suggestions)
            ->map(fn (array $item): string => trim($this->value($item, 'ten_xe').' '.$this->value($item, 'dac_diem')))
            ->filter()
            ->implode(' ');
    }

    private function productQaText(array $qa): string
    {
        if ($qa === []) {
            return '';
        }

        return collect($qa)
            ->map(fn (array $item): string => trim($this->value($item, 'khach_hang').' '.$this->value($item, 'nhan_vien')))
            ->filter()
            ->implode(' ');
    }

    private function listText(array $items, string $label): string
    {
        $values = array_values(array_filter(array_map(fn ($item): string => $this->stringifyValue($item), $items)));

        return $values === []
            ? ''
            : $label.': '.implode('; ', $values).'.';
    }

    private function loanDocumentGroups(array $groups): string
    {
        if ($groups === []) {
            return '';
        }

        return collect($groups)
            ->map(function (array $items, string $group): string {
                return str_replace('_', ' ', $group).': '.implode('; ', array_filter(array_map(fn ($item): string => $this->stringifyValue($item), $items)));
            })
            ->implode('. ');
    }

    private function estimateText(array $estimate): string
    {
        if ($estimate === []) {
            return '';
        }

        return collect($estimate)
            ->map(fn ($value, string $key): string => str_replace('_', ' ', $key).': '.$this->stringifyValue($value))
            ->implode('; ');
    }

    private function json(string $filename): array
    {
        $path = base_path('Modules/Chatbot/Data/'.$filename);

        if (! is_file($path)) {
            $path = base_path($filename);
        }

        if (! is_file($path)) {
            return [];
        }

        $contents = trim((string) file_get_contents($path));
        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strcspn($contents, '[{');
        $end = max(strrpos($contents, ']') ?: 0, strrpos($contents, '}') ?: 0);

        if ($end > $start) {
            return json_decode(substr($contents, $start, $end - $start + 1), true) ?: [];
        }

        return [];
    }

    private function value(array $row, string $key): string
    {
        return $this->stringifyValue($row[$key] ?? '');
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'co' : 'khong';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            return collect($value)
                ->map(function ($item, $key): string {
                    $text = $this->stringifyValue($item);

                    if ($text === '') {
                        return '';
                    }

                    return is_string($key) && ! is_numeric($key)
                        ? str_replace('_', ' ', $key).': '.$text
                        : $text;
                })
                ->filter()
                ->implode('; ');
        }

        return '';
    }

    private function normalize(string $value): string
    {
        return str((string) $value)->lower()->ascii()->squish()->toString();
    }
}
