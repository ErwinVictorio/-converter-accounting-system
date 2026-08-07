import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { Link, usePage } from "@inertiajs/react";
import { LayoutDashboard, ShoppingCart, FileDown, Building2, DatabasePlus, ChartNoAxesCombined } from "lucide-react";

// Lahat na ay babagtas bilang normal SPA Page navigation
const navItems = [
    {
        title: "Dashboard",
        url: "/",
        icon: LayoutDashboard,
    },
    {
        title: "Record Entry",
        url: "/records",
        icon: DatabasePlus,
    },

    {
        title: "Generate DatFile",
        url: "/generate-datfile",
        icon: ChartNoAxesCombined,
    },

];

export function AppSidebar() {
    const { url: currentUrl } = usePage();

    return (
        <Sidebar collapsible="icon" className="border-r border-gray-200 bg-white">
            {/* Header */}
            <SidebarHeader className="h-16 flex items-center px-4 border-b">
                <div className="flex items-center gap-3 font-bold text-gray-800 text-base">
                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <Building2 className="h-5 w-5" />
                    </div>
                    <span className="truncate">Fortress Steel</span>
                </div>
            </SidebarHeader>

            {/* Content Links */}
            <SidebarContent className="px-2 py-4">
                <SidebarGroup>
                    <SidebarGroupLabel className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-2">
                        Main Menu
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu className="space-y-1">
                            {navItems.map((item) => {
                                const IconComponent = item.icon;

                                return (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={currentUrl === item.url}
                                            className="w-full flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors hover:bg-gray-100 data-[active=true]:bg-blue-50 data-[active=true]:text-blue-600"
                                        >
                                            <Link href={item.url} className="flex items-center gap-3 w-full">
                                                <IconComponent className="h-4 w-4 shrink-0" />
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                );
                            })}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            {/* Footer */}
            <SidebarFooter className="p-4 border-t border-gray-100 text-xs text-gray-400">
                <span>System v1.0</span>
            </SidebarFooter>
        </Sidebar>
    );
}