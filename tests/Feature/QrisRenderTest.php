<?php

namespace Tests\Feature;

use App\Services\XenditService;
use Tests\TestCase;

class QrisRenderTest extends TestCase
{
    private string $qrString = '00020101021226570011ID.DANA.WWW011893600915068923440102096892344010303UKE51440014ID.CO.QRIS.WWW0215ID20243291934190303UKE520489995303360540471205802ID5917GASCPNS Indonesia6012Kab. Cilacap61055322462720115XXLJjwb3BjAiYzw60490011ID.DANA.WWW0425MER2021071400774509608641050116304DD95';

    /**
     * QRIS mengandung huruf kecil dan titik, jadi harus BYTE mode. Kalau
     * library memilih ALPHANUMERIC, datanya rusak dan QR ditolak aplikasi bank.
     */
    public function test_qris_is_encoded_in_byte_mode()
    {
        $code = \BaconQrCode\Encoder\Encoder::encode(
            $this->qrString,
            \BaconQrCode\Common\ErrorCorrectionLevel::M(),
            \BaconQrCode\Encoder\Encoder::DEFAULT_BYTE_MODE_ENCODING
        );

        $this->assertSame('BYTE', (string)$code->getMode());
    }

    public function test_render_qr_svg_produces_valid_svg()
    {
        $svg = app(XenditService::class)->renderQrSvg($this->qrString);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertGreaterThan(1000, strlen($svg));
    }
}
