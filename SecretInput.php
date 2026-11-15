<?php
namespace TypechoPlugin\SoraglassGallery;

/** Mask in HTML; reveal through the authenticated, CSRF-protected no-store API. */
final class SecretInput extends \Typecho\Widget\Helper\Form\Element\Password
{
    public function input(?string $name = null, ?array $options = null): ?\Typecho\Widget\Helper\Layout
    {
        $input = parent::input($name, $options);
        $input->setAttribute('autocomplete', 'new-password')->setAttribute('data-sgga-secret', $name);
        $this->container->removeItem($input);
        $control = new \Typecho\Widget\Helper\Layout('div', ['class' => 'sgga-secret-control']);
        $control->addItem($input);
        $toggle = new \Typecho\Widget\Helper\Layout('button', ['type' => 'button', 'data-secret-toggle' => $name, 'aria-label' => '显示密钥', 'aria-pressed' => 'false']);
        $toggle->html('<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>');
        $control->addItem($toggle);
        $this->container->addItem($control);
        return $input;
    }

    public function value($value): \Typecho\Widget\Helper\Form\Element
    {
        return parent::value(($value !== '' || Settings::hasCredential($this->name)) ? Settings::MASK : '');
    }
}
