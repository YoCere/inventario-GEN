import flatpickr from "flatpickr";
import TomSelect from "tom-select";
import "trix";
import "trix/dist/trix.css";
import "./bootstrap";
import "./../../vendor/power-components/livewire-powergrid/dist/powergrid";
import "./heic-converter";

// @ts-ignore
window.TomSelect = TomSelect;
window.flatpickr = flatpickr;

// PWA: registrar service worker (habilita instalación en Android/Chrome).
if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("/sw.js").catch(() => {});
    });
}

// import Alpine from 'alpinejs';
// window.Alpine = Alpine;
// Alpine.start();
