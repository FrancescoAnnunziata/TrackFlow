<x-layout title="Registrazione">
    <section class="bg-layer">
        <div class="max-w-md px-4 py-10 mx-auto sm:px-6 lg:px-8 lg:py-14">
            <div class="bg-surface border border-layer-line rounded-xl shadow-sm p-6 sm:p-8">
                <div class="mb-8 text-center">
                    <h1 class="text-2xl font-bold text-foreground">Crea il tuo account</h1>
                    <p class="mt-2 text-sm text-muted-foreground">Registrati per iniziare a tracciare ore e spese.</p>
                </div>

                @if ($errors->any())
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        <ul class="space-y-1 list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="/register" class="space-y-4">
                    @csrf

                    <div>
                        <label for="name" class="block mb-2 text-sm font-medium text-foreground">Nome</label>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            required
                            autocomplete="given-name"
                            class="py-3 px-4 block w-full rounded-lg border {{ $errors->has('name') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-gray-200 focus:border-blue-500 focus:ring-blue-500' }} text-sm disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="Mario"
                        >
                        @error('name')
                            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="surname" class="block mb-2 text-sm font-medium text-foreground">Cognome</label>
                        <input
                            id="surname"
                            name="surname"
                            type="text"
                            value="{{ old('surname') }}"
                            required
                            autocomplete="family-name"
                            class="py-3 px-4 block w-full rounded-lg border {{ $errors->has('surname') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-gray-200 focus:border-blue-500 focus:ring-blue-500' }} text-sm disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="Rossi"
                        >
                        @error('surname')
                            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="block mb-2 text-sm font-medium text-foreground">Email</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autocomplete="email"
                            class="py-3 px-4 block w-full rounded-lg border {{ $errors->has('email') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-gray-200 focus:border-blue-500 focus:ring-blue-500' }} text-sm disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="nome@azienda.it"
                        >
                        @error('email')
                            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="block mb-2 text-sm font-medium text-foreground">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            class="py-3 px-4 block w-full rounded-lg border {{ $errors->has('password') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-gray-200 focus:border-blue-500 focus:ring-blue-500' }} text-sm disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="Inserisci una password"
                        >
                        @error('password')
                            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block mb-2 text-sm font-medium text-foreground">Conferma password</label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            class="py-3 px-4 block w-full rounded-lg border {{ $errors->has('password_confirmation') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-gray-200 focus:border-blue-500 focus:ring-blue-500' }} text-sm disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="Ripeti la password"
                        >
                        @error('password_confirmation')
                            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        class="w-full py-3 px-4 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-lg border border-transparent bg-primary text-primary-foreground hover:opacity-90 focus:outline-hidden focus:opacity-90 disabled:opacity-50 disabled:pointer-events-none"
                    >
                        Crea account
                    </button>
                </form>
            </div>
        </div>
    </section>
</x-layout>
