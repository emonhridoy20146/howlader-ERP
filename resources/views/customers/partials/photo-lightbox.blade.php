{{-- Expects ancestor x-data with: photoLightbox { src, alt }, openCustomerPhoto(src, alt), closeCustomerPhoto() --}}
<template x-teleport="body">
	<div
		x-show="photoLightbox.src"
		x-cloak
		x-transition:enter="transition ease-out duration-200"
		x-transition:enter-start="opacity-0"
		x-transition:enter-end="opacity-100"
		x-transition:leave="transition ease-in duration-150"
		x-transition:leave-start="opacity-100"
		x-transition:leave-end="opacity-0"
		class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/85 p-6 backdrop-blur-[2px]"
		@click.self="closeCustomerPhoto()"
		@keydown.escape.window="if (photoLightbox.src) closeCustomerPhoto()"
		role="dialog"
		aria-modal="true"
		:aria-label="photoLightbox.alt ? 'Photo: ' + photoLightbox.alt : 'Customer photo'"
	>
		<button
			type="button"
			class="absolute right-4 top-4 rounded-full bg-white/15 p-2.5 text-white hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
			@click="closeCustomerPhoto()"
			aria-label="Close"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
		</button>
		<img
			:src="photoLightbox.src"
			:alt="photoLightbox.alt"
			class="max-h-[min(90vh,90dvh)] max-w-full rounded-lg object-contain shadow-2xl ring-1 ring-white/10"
			@click.stop
		>
	</div>
</template>
