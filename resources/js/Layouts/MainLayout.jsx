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

        <SidebarInset className="flex flex-col min-w-0">
          <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between border-b bg-white px-4 md:px-6">
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

          <main className="flex-1 p-4 md:p-6 bg-slate-50/50 overflow-y-auto">
            <div className="max-w-7xl mx-auto space-y-6">
              {children}
            </div>
          </main>
        </SidebarInset>
      </SidebarProvider>
    </TooltipProvider>
  );
}