<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\ValidationServiceInterface;

final class ValidationService implements ValidationServiceInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_Validator_Service')) { $this->legacy = new \WP_MCP_AI_Validator_Service(); } }
    public function validateEmail(string $email): array { return $this->legacy && \method_exists($this->legacy, 'is_email') ? $this->wrap($this->legacy->is_email($email), 'email') : $this->phpEmail($email); }
    public function validatePhone(string $phone, string $countryCode = ''): array { return $this->legacy && \method_exists($this->legacy, 'is_phone') ? $this->wrap($this->legacy->is_phone($phone, $countryCode), 'phone') : ['valid' => true, 'value' => $phone]; }
    public function validateUrl(string $url): array { return $this->legacy && \method_exists($this->legacy, 'is_url') ? $this->wrap($this->legacy->is_url($url), 'url') : ['valid' => (bool) \filter_var($url, \FILTER_VALIDATE_URL), 'value' => $url]; }
    public function validateCreditCard(string $number): array { return $this->legacy && \method_exists($this->legacy, 'is_credit_card') ? $this->wrap($this->legacy->is_credit_card($number), 'credit_card') : ['valid' => false, 'value' => $number]; }
    public function sanitize(string $value, string $type = 'text'): string { return \function_exists('sanitize_text_field') && $type === 'text' ? \sanitize_text_field($value) : \htmlspecialchars($value, \ENT_QUOTES, 'UTF-8'); }
    public function isAvailable(): bool { return $this->legacy !== null; }
    private function wrap($result, string $field): array { return \is_wp_error($result) ? ['valid' => false, 'error' => $result->get_error_message()] : ['valid' => (bool) $result, 'value' => $field]; }
    private function phpEmail(string $email): array { return ['valid' => (bool) \filter_var($email, \FILTER_VALIDATE_EMAIL), 'value' => $email]; }
}
