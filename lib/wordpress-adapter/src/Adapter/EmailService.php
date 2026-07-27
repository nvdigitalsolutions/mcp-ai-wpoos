<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;

final class EmailService implements EmailServiceInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_Nodemailer_Service')) { $this->legacy = new \WP_MCP_AI_Nodemailer_Service(); } }
    public function send(array $message): array {
        if (!$this->legacy) { return ['success' => false, 'error' => 'Email service unavailable']; }
        try { $r = $this->legacy->send_email($message); return \is_wp_error($r) ? ['success' => false, 'error' => $r->get_error_message()] : \array_merge(['success' => true], \is_array($r) ? $r : []); }
        catch (\Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }
    public function isAvailable(): bool { return $this->legacy !== null && \method_exists($this->legacy, 'is_available') && $this->legacy->is_available(); }
    public function validateRecipient(string $email): bool { return \function_exists('is_email') ? (bool) \is_email($email) : (bool) \filter_var($email, \FILTER_VALIDATE_EMAIL); }
}
