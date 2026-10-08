<?php /** Hiasan sisi kanan kartu masuk: dua bidang ungu bertepi awan di atas dan bawah. Murni dekoratif. */ ?>
<svg class="auth__art" viewBox="0 0 600 600" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="authTop" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" class="auth__stop auth__stop--a"/>
            <stop offset="1" class="auth__stop auth__stop--b"/>
        </linearGradient>
        <linearGradient id="authBottom" x1="1" y1="0" x2="0" y2="1">
            <stop offset="0" class="auth__stop auth__stop--b"/>
            <stop offset="1" class="auth__stop auth__stop--c"/>
        </linearGradient>
        <radialGradient id="authGlow" cx="0.5" cy="0.5" r="0.5">
            <stop offset="0" stop-color="#fff" stop-opacity="0.28"/>
            <stop offset="1" stop-color="#fff" stop-opacity="0"/>
        </radialGradient>
    </defs>
    <path fill="url(#authTop)"
          d="M0 0H600V128C566 112 545 156 508 146C474 137 452 96 414 106C382 114 372 158 330 156C290 154 282 112 242 116C204 120 190 76 150 66C112 57 70 34 0 0Z"/>
    <circle cx="440" cy="34" r="150" fill="url(#authGlow)"/>
    <path fill="url(#authBottom)"
          d="M0 600L36 566C78 534 112 526 152 504C192 482 204 440 246 440C286 440 296 482 336 480C376 478 388 438 428 434C468 430 488 470 526 464C558 459 578 444 600 438V600Z"/>
    <circle cx="520" cy="590" r="170" fill="url(#authGlow)"/>
</svg>
