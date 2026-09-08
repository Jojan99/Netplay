<?php

namespace App\Support;

use FFMpeg\Format\Audio\DefaultAudio;

/**
 * OGG/Opus mono a 48 kHz: el único formato de nota de voz que acepta la API de WhatsApp.
 */
class OpusAudioFormat extends DefaultAudio
{
    public function __construct()
    {
        $this->audioCodec = 'libopus';
        $this->audioKiloBitrate = 32;
        $this->audioChannels = 1;
    }

    public function getAvailableAudioCodecs(): array
    {
        return ['libopus'];
    }

    public function getExtraParams(): array
    {
        return ['-ar', '48000', '-application', 'voip', '-vbr', 'on'];
    }
}
