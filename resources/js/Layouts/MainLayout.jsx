import { SidebarProvider, SidebarTrigger, SidebarInset } from "@/components/ui/sidebar";
import { TooltipProvider } from "@/components/ui/tooltip"; // <-- 1. I-import ang TooltipProvider
import { AppSidebar } from "@/components/app-sidebar";
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from "@/components/ui/breadcrumb";
import { Separator } from "@/components/ui/separator";
import { Link } from "@inertiajs/react";

export default function MainLayout({ children, title = "Dashboard" }) {
  return (
    // 2. Ibalot ang SidebarProvider sa TooltipProvider
    <TooltipProvider delayDuration={0}>
      <SidebarProvider>
        <AppSidebar />

        <SidebarInset className="flex min-w-0 flex-col overflow-x-hidden">
          <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between border-b bg-white px-3 sm:px-4 lg:px-5 xl:px-6">
            <div className="flex items-center gap-2 sm:gap-4 min-w-0">
              <SidebarTrigger className="-ml-1" />
              <Separator orientation="vertical" className="h-4" />

              <Breadcrumb className="min-w-0">
                <BreadcrumbList className="flex items-center gap-1.5 sm:gap-2 text-sm">
                  <BreadcrumbItem className="hidden sm:inline-flex">
                    <BreadcrumbLink asChild>
                      <Link href="/" className="text-gray-500 hover:text-gray-900 transition-colors">
                        Fortress Steel
                      </Link>
                    </BreadcrumbLink>
                  </BreadcrumbItem>
                  
                  <BreadcrumbSeparator className="hidden sm:inline-flex" />

                  <BreadcrumbItem className="truncate">
                    <BreadcrumbPage className="font-bold text-gray-900 truncate">
                      {title}
                    </BreadcrumbPage>
                  </BreadcrumbItem>
                </BreadcrumbList>
              </Breadcrumb>
            </div>
          </header>

          {/* Hindi <main> ito: <main> na mismo ang SidebarInset, kaya mag-nest sana. */}
          <div className="min-w-0 flex-1 bg-slate-50/50 p-3 sm:p-4 lg:p-5 xl:p-6">
            <div className="mx-auto w-full min-w-0 max-w-7xl space-y-6">
              {children}
            </div>
          </div>
        </SidebarInset>
      </SidebarProvider>
    </TooltipProvider>
  );
}