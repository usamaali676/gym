@php $iconBtn = 'btn btn-ghost !min-h-11 !min-w-11 !px-0'; @endphp
<button type="button" class="{{ $iconBtn }}" @click="toggleSkeleton()" :aria-pressed="skeletonOn" aria-label="Show skeleton"><x-icon name="scan" class="size-5" ::class="skeletonOn ? 'text-neon' : 'text-muted'"/></button>
<button type="button" class="{{ $iconBtn }}" @click="toggleMirror()" :aria-pressed="mirror" aria-label="Mirror the video"><x-icon name="swap" class="size-5" ::class="mirror ? 'text-neon' : 'text-muted'"/></button>
<button type="button" class="{{ $iconBtn }}" @click="toggleVoice()" :aria-pressed="voiceOn" aria-label="Voice coach"><x-icon name="mic" class="size-5" ::class="voiceOn ? 'text-neon' : 'text-muted'"/></button>
<button type="button" class="{{ $iconBtn }}" @click="toggleSound()" :aria-pressed="soundOn" aria-label="Sound cues"><x-icon name="volume" class="size-5" ::class="soundOn ? 'text-neon' : 'text-muted'"/></button>
<button type="button" class="{{ $iconBtn }}" @click="switchCamera()" aria-label="Switch camera"><x-icon name="camera" class="size-5"/></button>
<button type="button" class="{{ $iconBtn }}" @click="toggleFullscreen()" aria-label="Full screen"><x-icon name="grid" class="size-5"/></button>
