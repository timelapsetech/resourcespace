<?php

/**
 * Omakase Player needs APIs that browsers withhold on plain HTTP (secure context only).
 * Inline script so it cannot 404 and runs before the ES module bundle.
 */
function image_sequence_render_crypto_random_uuid_polyfill_script(): void
{
    ?>
    <script>
    (function () {
        'use strict';
        function randomUUIDImpl(getRandomValues) {
            if (typeof getRandomValues === 'function') {
                var bytes = new Uint8Array(16);
                getRandomValues(bytes);
                bytes[6] = (bytes[6] & 0x0f) | 0x40;
                bytes[8] = (bytes[8] & 0x3f) | 0x80;
                var hex = Array.prototype.map.call(bytes, function (byte) {
                    return byte.toString(16).padStart(2, '0');
                }).join('');
                return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
                    + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) {
                var random = Math.random() * 16 | 0;
                var value = char === 'x' ? random : (random & 0x3 | 0x8);
                return value.toString(16);
            });
        }

        function installRandomUUID(cryptoObj) {
            if (!cryptoObj || typeof cryptoObj.randomUUID === 'function') {
                return;
            }
            var impl = function randomUUID() {
                return randomUUIDImpl(cryptoObj.getRandomValues
                    ? cryptoObj.getRandomValues.bind(cryptoObj)
                    : null);
            };
            try {
                cryptoObj.randomUUID = impl;
            } catch (e) {
                try {
                    Object.defineProperty(cryptoObj, 'randomUUID', {
                        value: impl,
                        configurable: true,
                        writable: true,
                    });
                } catch (e2) {
                    /* ignore */
                }
            }
        }

        var root = typeof globalThis !== 'undefined' ? globalThis : window;
        if (!root.crypto) {
            try {
                root.crypto = {};
            } catch (e) {
                try {
                    Object.defineProperty(root, 'crypto', {
                        value: {},
                        configurable: true,
                    });
                } catch (e2) {
                    /* ignore */
                }
            }
        }
        installRandomUUID(root.crypto);
        if (typeof window !== 'undefined' && window.crypto && window.crypto !== root.crypto) {
            installRandomUUID(window.crypto);
        }
        window.imageSequenceEnsureCryptoRandomUUID = installRandomUUID;

        function installHttpAudioShim() {
            if (typeof window === 'undefined' || window.__imgseqHttpAudioShim) {
                return;
            }
            window.__imgseqHttpAudioShim = true;

            var trackedAudioContexts = [];

            function trackAudioContext(ctx) {
                if (ctx && trackedAudioContexts.indexOf(ctx) === -1) {
                    trackedAudioContexts.push(ctx);
                }
                return ctx;
            }

            function wrapAudioContextClass(Ctx) {
                if (!Ctx || Ctx.__imgseqWrapped) {
                    return;
                }
                Ctx.__imgseqWrapped = true;
                var Original = Ctx;
                function WrappedContext() {
                    var ctx = new Original(...arguments);
                    return trackAudioContext(ctx);
                }
                WrappedContext.prototype = Original.prototype;
                if (Ctx === window.AudioContext) {
                    window.AudioContext = WrappedContext;
                } else if (Ctx === window.webkitAudioContext) {
                    window.webkitAudioContext = WrappedContext;
                } else if (Ctx === window.OfflineAudioContext) {
                    window.OfflineAudioContext = WrappedContext;
                }
            }

            if (window.AudioContext) {
                wrapAudioContextClass(window.AudioContext);
            }
            if (window.webkitAudioContext && window.webkitAudioContext !== window.AudioContext) {
                wrapAudioContextClass(window.webkitAudioContext);
            }
            if (window.OfflineAudioContext) {
                wrapAudioContextClass(window.OfflineAudioContext);
            }

            function createFallbackWorkletNode(context) {
                var gain = context.createGain();
                gain.gain.value = 0;
                gain.port = {
                    postMessage: function () {},
                    start: function () {},
                    close: function () {},
                    onmessage: null,
                };
                gain.parameters = {
                    get: function () {
                        return { value: 0, setValueAtTime: function () {} };
                    },
                };
                return gain;
            }

            var NativeAudioWorkletNode = window.AudioWorkletNode;
            window.AudioWorkletNode = function (context, name, options) {
                if (NativeAudioWorkletNode) {
                    try {
                        return new NativeAudioWorkletNode(context, name, options);
                    } catch (e) {
                        /* HTTP / missing worklet scope — fall back below */
                    }
                }
                return createFallbackWorkletNode(context);
            };
            if (NativeAudioWorkletNode && NativeAudioWorkletNode.prototype) {
                window.AudioWorkletNode.prototype = NativeAudioWorkletNode.prototype;
            }

            var workletStub = {
                addModule: function () {
                    return Promise.resolve();
                },
            };

            [window.AudioContext, window.webkitAudioContext, window.OfflineAudioContext].forEach(function (Ctx) {
                if (!Ctx || !Ctx.prototype || Ctx.prototype.__imgseqAudioWorkletPatched) {
                    return;
                }
                Ctx.prototype.__imgseqAudioWorkletPatched = true;
                var nativeDesc = Object.getOwnPropertyDescriptor(Ctx.prototype, 'audioWorklet');
                try {
                    Object.defineProperty(Ctx.prototype, 'audioWorklet', {
                        get: function () {
                            if (nativeDesc && nativeDesc.get) {
                                try {
                                    var nativeWorklet = nativeDesc.get.call(this);
                                    if (nativeWorklet && typeof nativeWorklet.addModule === 'function') {
                                        return nativeWorklet;
                                    }
                                } catch (e) {
                                    /* ignore */
                                }
                            }
                            if (!this.__imgseqAudioWorklet) {
                                this.__imgseqAudioWorklet = workletStub;
                            }
                            return this.__imgseqAudioWorklet;
                        },
                        configurable: true,
                    });
                } catch (e) {
                    /* ignore */
                }
            });

            window.imageSequenceResumeAudioContexts = function () {
                trackedAudioContexts.forEach(function (ctx) {
                    if (ctx && typeof ctx.resume === 'function' && ctx.state === 'suspended') {
                        ctx.resume().catch(function () {});
                    }
                });
            };
        }

        installHttpAudioShim();
    }());
    </script>
    <?php
}
