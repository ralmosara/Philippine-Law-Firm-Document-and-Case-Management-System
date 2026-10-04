import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { ApiError } from './shared/api/axios'
import { ToastProvider } from './shared/ui/Toast'
import { router } from './AppRouter'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: true,
      // Try once even offline (the service worker may answer with a saved
      // copy); if that fails, wait for the connection instead of replacing
      // what is on screen with an error.
      networkMode: 'offlineFirst',
      // Retry transient failures, never client errors (4xx): they will not fix themselves.
      retry: (failureCount, error) => {
        const status = ApiError.from(error).status
        return (status === 0 || status >= 500) && failureCount < 2
      },
    },
    // Saves always run: the few that can wait for a signal keep themselves
    // on the device (shared/offline); the rest report that there is none.
    mutations: { networkMode: 'always' },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>
  )
}
