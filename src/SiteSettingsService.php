<?php
declare(strict_types=1);

namespace Lezhai;

use PDO;
use RuntimeException;

final class SiteSettingsService
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(): array
    {
        $row = $this->pdo->query('SELECT icp_number,police_number FROM site_settings WHERE id=1')->fetch();
        return is_array($row) ? $row : ['icp_number' => '', 'police_number' => ''];
    }

    public function normalize(array $input): array
    {
        $settings = [
            'icp_number' => trim((string) ($input['icp_number'] ?? '')),
            'police_number' => trim((string) ($input['police_number'] ?? '')),
        ];
        foreach ($settings as $value) {
            if (mb_strlen($value) > 80) throw new RuntimeException('备案号不能超过 80 个字符。');
            if (preg_match('/[\x00-\x1F\x7F]/u', $value)) throw new RuntimeException('备案号不能包含控制字符。');
        }
        if ($settings['police_number'] !== ''
            && (!str_contains($settings['police_number'], '公网安备') || $this->policeCode($settings['police_number']) === '')) {
            throw new RuntimeException('公安联网备案号需包含“公网安备”和 14 位备案代码。');
        }
        return $settings;
    }

    public function save(array $input): array
    {
        $settings = $this->normalize($input);
        $this->pdo->prepare(
            'INSERT INTO site_settings(id,icp_number,police_number,updated_at) VALUES(1,?,?,NOW())
             ON CONFLICT(id) DO UPDATE SET icp_number=EXCLUDED.icp_number,police_number=EXCLUDED.police_number,updated_at=NOW()'
        )->execute([$settings['icp_number'], $settings['police_number']]);
        return $settings;
    }

    public function policeCode(string $number): string
    {
        return preg_match('/(?<!\d)(\d{14})(?!\d)/', $number, $match) ? $match[1] : '';
    }

    public function footerHtml(string $iconPath): string
    {
        $settings = $this->get();
        $links = [];
        if ($settings['icp_number'] !== '') {
            $links[] = '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">' . e($settings['icp_number']) . '</a>';
        }
        if ($settings['police_number'] !== '') {
            $code = $this->policeCode((string) $settings['police_number']);
            if ($code !== '') {
                $links[] = '<a href="https://beian.mps.gov.cn/#/query/webSearch?code=' . e($code) . '" target="_blank" rel="noopener noreferrer"><img src="' . e($iconPath) . '" width="20" height="20" alt="" aria-hidden="true">' . e($settings['police_number']) . '</a>';
            }
        }
        return $links === [] ? '' : '<span class="filing-links">' . implode('<span aria-hidden="true">·</span>', $links) . '</span>';
    }
}
