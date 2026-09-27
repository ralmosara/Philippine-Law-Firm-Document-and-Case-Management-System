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
      // Retry transient failures, never client errors (4xx): they will not fix themselves.
      retry: (failureCount, error) => {
        const status = ApiError.from(error).status
        return (status === 0 || status >= 500) && failureCount < 2
      },
    },
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
