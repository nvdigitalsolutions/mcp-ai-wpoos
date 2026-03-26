cmd_Release/obj.target/canvas/src/backend/ImageBackend.o := g++ -o Release/obj.target/canvas/src/backend/ImageBackend.o ../src/backend/ImageBackend.cc '-DNODE_GYP_MODULE_NAME=canvas' '-DUSING_UV_SHARED=1' '-DUSING_V8_SHARED=1' '-DV8_DEPRECATION_WARNINGS=1' '-D_GLIBCXX_USE_CXX11_ABI=1' '-D_FILE_OFFSET_BITS=64' '-D_LARGEFILE_SOURCE' '-D__STDC_FORMAT_MACROS' '-DOPENSSL_NO_PINSHARED' '-DOPENSSL_THREADS' '-DHAVE_JPEG' '-DHAVE_GIF' '-DHAVE_RSVG' '-DBUILDING_NODE_EXTENSION' -I/home/runner/.cache/node-gyp/24.14.0/include/node -I/home/runner/.cache/node-gyp/24.14.0/src -I/home/runner/.cache/node-gyp/24.14.0/deps/openssl/config -I/home/runner/.cache/node-gyp/24.14.0/deps/openssl/openssl/include -I/home/runner/.cache/node-gyp/24.14.0/deps/uv/include -I/home/runner/.cache/node-gyp/24.14.0/deps/zlib -I/home/runner/.cache/node-gyp/24.14.0/deps/v8/include -I../../nan -I/usr/include/cairo -I/usr/include/libpng16 -I/usr/include/freetype2 -I/usr/include/pixman-1 -I/usr/include/pango-1.0 -I/usr/include/glib-2.0 -I/usr/lib/x86_64-linux-gnu/glib-2.0/include -I/usr/include/harfbuzz -I/usr/include/libmount -I/usr/include/blkid -I/usr/include/fribidi -I/opt/homebrew/include -I/usr/include/librsvg-2.0 -I/usr/include/gdk-pixbuf-2.0 -I/usr/include/x86_64-linux-gnu -I/usr/include/webp  -fPIC -pthread -Wall -Wextra -Wno-unused-parameter -Wno-cast-function-type -m64 -O3 -fno-omit-frame-pointer -fno-rtti -fno-strict-aliasing -std=gnu++20 -MMD -MF ./Release/.deps/Release/obj.target/canvas/src/backend/ImageBackend.o.d.raw   -c
Release/obj.target/canvas/src/backend/ImageBackend.o: \
 ../src/backend/ImageBackend.cc ../src/backend/ImageBackend.h \
 ../src/backend/Backend.h /usr/include/cairo/cairo.h \
 /usr/include/cairo/cairo-version.h /usr/include/cairo/cairo-features.h \
 /usr/include/cairo/cairo-deprecated.h ../src/backend/../dll_visibility.h \
 ../../nan/nan.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node_version.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/uv.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/uv/errno.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/uv/version.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/uv/unix.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/uv/threadpool.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/uv/linux.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/common.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8config.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-array-buffer.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-local-handle.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-handle-base.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-internal.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8config.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-memory-span.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-object.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-maybe.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/internal/conditional-stack-allocated.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/macros.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/internal/compiler-specific.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/type-traits.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-persistent-handle.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-weak-callback-info.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-primitive.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-data.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-value.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-sandbox.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-traced-handle.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-platform.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-source-location.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-container.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-context.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-snapshot.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-isolate.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-callbacks.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-promise.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-debug.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-script.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-message.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-embedder-heap.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-exception.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-function-callback.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-microtask.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-statistics.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-unwinder.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-embedder-state-scope.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-date.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-extension.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-external.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-function.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-template.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-initialization.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-json.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-locker.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-microtask-queue.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-primitive-object.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-proxy.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-regexp.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-typed-array.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-value-serializer.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-version.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8-wasm.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node_version.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node_api.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/js_native_api.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/js_native_api_types.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node_api_types.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node_buffer.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/node_object_wrap.h \
 ../../nan/nan_callbacks.h ../../nan/nan_callbacks_12_inl.h \
 ../../nan/nan_maybe_43_inl.h ../../nan/nan_converters.h \
 ../../nan/nan_converters_43_inl.h ../../nan/nan_new.h \
 ../../nan/nan_implementation_12_inl.h ../../nan/nan_persistent_12_inl.h \
 ../../nan/nan_weak.h ../../nan/nan_object_wrap.h ../../nan/nan_private.h \
 ../../nan/nan_typedarray_contents.h ../../nan/nan_json.h \
 ../../nan/nan_scriptorigin.h \
 /home/runner/.cache/node-gyp/24.14.0/include/node/v8.h
../src/backend/ImageBackend.cc:
../src/backend/ImageBackend.h:
../src/backend/Backend.h:
/usr/include/cairo/cairo.h:
/usr/include/cairo/cairo-version.h:
/usr/include/cairo/cairo-features.h:
/usr/include/cairo/cairo-deprecated.h:
../src/backend/../dll_visibility.h:
../../nan/nan.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node_version.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/uv.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/uv/errno.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/uv/version.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/uv/unix.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/uv/threadpool.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/uv/linux.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/common.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8config.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-array-buffer.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-local-handle.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-handle-base.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-internal.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8config.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-memory-span.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-object.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-maybe.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/internal/conditional-stack-allocated.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/macros.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/internal/compiler-specific.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/cppgc/type-traits.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-persistent-handle.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-weak-callback-info.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-primitive.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-data.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-value.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-sandbox.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-traced-handle.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-platform.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-source-location.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-container.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-context.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-snapshot.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-isolate.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-callbacks.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-promise.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-debug.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-script.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-message.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-embedder-heap.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-exception.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-function-callback.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-microtask.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-statistics.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-unwinder.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-embedder-state-scope.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-date.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-extension.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-external.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-function.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-template.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-initialization.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-json.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-locker.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-microtask-queue.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-primitive-object.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-proxy.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-regexp.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-typed-array.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-value-serializer.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-version.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8-wasm.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node_version.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node_api.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/js_native_api.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/js_native_api_types.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node_api_types.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node_buffer.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/node_object_wrap.h:
../../nan/nan_callbacks.h:
../../nan/nan_callbacks_12_inl.h:
../../nan/nan_maybe_43_inl.h:
../../nan/nan_converters.h:
../../nan/nan_converters_43_inl.h:
../../nan/nan_new.h:
../../nan/nan_implementation_12_inl.h:
../../nan/nan_persistent_12_inl.h:
../../nan/nan_weak.h:
../../nan/nan_object_wrap.h:
../../nan/nan_private.h:
../../nan/nan_typedarray_contents.h:
../../nan/nan_json.h:
../../nan/nan_scriptorigin.h:
/home/runner/.cache/node-gyp/24.14.0/include/node/v8.h:
