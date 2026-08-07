import { SidebarProvider, SidebarTrigger, SidebarInset } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/app-sidebar";
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from "@/components/ui/breadcrumb";
import { Separator } from "@/components/ui/separator";
import { Link } from "@inertiajs/react";

export default function MainLayout({ children, title = "Dashboard" }) {
    return (
        <SidebarProvider>
            <AppSidebar />
            <SidebarInset>
                <header className="flex h-16 shrink-0 items-center gap-2 border-b bg-white px-4 md:px-6">
                    <div className="flex items-center lg:gap-20">
                        <SidebarTrigger className="-ml-1" />
                        <Separator orientation="vertical" className="h-4" />
                        <Breadcrumb>
                            <BreadcrumbList className="flex items-center gap-2">
                                <BreadcrumbItem className="hidden sm:inline-flex">
                                    <BreadcrumbLink asChild>
                                        <Link href="/" className="text-sm font-medium text-gray-500 hover:text-gray-900">
                                            Fortress Steel
                                        </Link>
                                    </BreadcrumbLink>
                                </BreadcrumbItem>
                                <BreadcrumbSeparator className="hidden sm:inline-flex" />
                                <BreadcrumbItem>
                                    <BreadcrumbPage className="text-sm font-bold text-gray-900">
                                        {title}
                                    </BreadcrumbPage>
                                </BreadcrumbItem>
                            </BreadcrumbList>
                        </Breadcrumb>
                    </div>
                </header>

                <div className="flex-1 p-6 bg-gray-50/50">
                    <div className="max-w-7xl mx-auto space-y-6">
                        {children}
                    </div>
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}