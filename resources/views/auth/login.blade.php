<x-layout title="Accesso">
    <section class="bg-layer">
        <div class="max-w-md px-4 py-10 mx-auto sm:px-6 lg:px-8 lg:py-14">
            <div class="bg-surface border border-layer-line rounded-xl shadow-sm p-6 sm:p-8">
                <div class="mb-8 text-center">
                    <h1 class="text-2xl font-bold text-foreground">Accedi al tuo account</h1>
                    <p class="mt-2 text-sm text-muted-foreground">Inserisci le tue credenziali per continuare.</p>
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

                <form method="POST" action="/login" class="space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="block mb-2 text-sm font-medium text-foreground">Email</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autocomplete="email"
                            class="py-3 px-4 block w-full rounded-lg border border-gray-200 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="nome@azienda.it"
                        >
                    </div>

                    <div>
                        <label for="password" class="block mb-2 text-sm font-medium text-foreground">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            class="py-3 px-4 block w-full rounded-lg border border-gray-200 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none"
                            placeholder="Inserisci la tua password"
                        >
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center">
                            <input
                                type="checkbox"
                                name="remember"
                                id="remember"
                                class="w-4 h-4 border border-gray-300 rounded"
                            >
                            <span class="ml-2 text-sm text-muted-foreground">Ricordami</span>
                        </label>
                        <a href="#" class="text-sm text-primary hover:opacity-90 focus:outline-hidden">Password dimenticata?</a>
                    </div>

                    <button
                        type="submit"
                        class="w-full py-3 px-4 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-lg border border-transparent bg-primary text-primary-foreground hover:opacity-90 focus:outline-hidden focus:opacity-90 disabled:opacity-50 disabled:pointer-events-none"
                    >
                        Accedi
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-sm text-muted-foreground">
                        Non hai un account?
                        <a href="/register" class="text-primary hover:opacity-90 focus:outline-hidden font-medium">Registrati</a>
                    </p>
                </div>
            </div>
        </div>
    </section>
</x-layout>
