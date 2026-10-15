<?php

namespace Rekhu\Assistant\View;

use Illuminate\View\Component;
use Rekhu\Assistant\RekhuAssistant;

/**
 * <x-rekhu-assistant /> in a layout: the chat button and panel. Signed-in users get their own chat; visitors get the
 * public chat when it is on (REKHU_ASSISTANT_GUESTS=true), else nothing. Every attribute is optional and overrides the
 * config: name, greeting, color.
 */
class Widget extends Component
{
    public function __construct(public ?string $name = null, public ?string $greeting = null, public ?string $color = null) {}

    public function shouldRender(): bool
    {
        return auth()->check() || (bool) config('rekhu-assistant.guests.enabled');
    }

    public function render()
    {
        $visitor = ! auth()->check();

        return view('rekhu-assistant::widget', [
            'settings' => [
                'endpoint' => $visitor ? route('rekhu.assistant.visitor') : route('rekhu.assistant.chat'),
                'visitor' => $visitor,
                'csrf' => csrf_token(),
                'name' => $this->name ?? config('rekhu-assistant.name', 'Assistant'),
                'greeting' => $this->greeting ?? ($visitor ? config('rekhu-assistant.guests.greeting') : null) ?? config('rekhu-assistant.greeting'),
                'color' => $this->color ?? config('rekhu-assistant.color', '#4f46e5'),
            ],
            'script' => route('rekhu.assistant.widget').'?v='.RekhuAssistant::VERSION,
        ]);
    }
}
