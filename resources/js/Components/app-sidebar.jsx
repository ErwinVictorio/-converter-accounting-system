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
    useSidebar,
} from "@/components/ui/sidebar";
import { Link, usePage } from "@inertiajs/react";
import {
    LayoutDashboard,
    Building2,
    DatabasePlus,
    ChartNoAxesCombined,
    Briefcase,
    Users,
    Ship
} from "lucide-react";


const navItems = [
    {
        title: "Dashboard",
        url: "/",
        icon: LayoutDashboard,
    },
    {
        title: "Import Excell File",
        url: "/records",
        icon: DatabasePlus,
    },
    {
        title: "Importation",
        url: "/importation",
        icon: Ship,
    },

    {
        title: "Manage Brokers",
        url: "/brokers",
        icon: Briefcase,
    },
    {
        title: "Generate DatFile",
        url: "/generate-datfile",
        icon: ChartNoAxesCombined,
    },

    {
        title: "Manage Suppliers",
        url: "/suppliers",
        icon: Users,
    },
    {
        title: "Manage Customers",
        url: "/customers",
        icon: Users,
    },

];

export function AppSidebar() {
    const { url: currentUrl } = usePage();
    const { isMobile, setOpenMobile } = useSidebar();

    // Kusa nitong isasara ang mobile drawer pagkatapos mag-click ng link
    const handleNavClick = () => {
        if (isMobile) {
            setOpenMobile(false);
        }
    };

    // Alisin ang query string, tapos tugma sa exact o parent na route
    const currentPath = currentUrl.split("?")[0];
    const isActiveUrl = (url) =>
        url === "/"
            ? currentPath === "/"
            : currentPath === url || currentPath.startsWith(`${url}/`);

    return (
        // collapsible="icon" = nagko-collapse sa 3rem rail sa desktop, sheet drawer sa mobile
        <Sidebar collapsible="icon">
            {/* Header — bumabagay sa expanded rail at sa makitid na icon rail */}
            <SidebarHeader className="h-16 flex items-center border-b border-gray-200 bg-white px-4 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
                <div className="flex items-center gap-3 font-bold text-gray-800 text-base overflow-hidden">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <Building2 className="h-5 w-5" />
                    </div>
                    <span className="truncate group-data-[collapsible=icon]:hidden">
                        Fortress Steel
                    </span>
                </div>
            </SidebarHeader>

            {/* Content Links */}
            <SidebarContent className="bg-white px-2 py-4">
                <SidebarGroup>
                    <SidebarGroupLabel className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-2">
                        Main Menu
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu className="space-y-1">
                            {navItems.map((item) => {
                                const IconComponent = item.icon;
                                const isActive = isActiveUrl(item.url);

                                return (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={isActive}
                                            tooltip={item.title}
                                            className="text-sm font-medium transition-colors hover:bg-gray-100 data-[active=true]:bg-blue-50 data-[active=true]:text-blue-600"
                                        >
                                            <Link
                                                href={item.url}
                                                onClick={handleNavClick}
                                                className="flex items-center gap-3 w-full"
                                            >
                                                <IconComponent className="h-4 w-4 shrink-0" />
                                                <span className="truncate group-data-[collapsible=icon]:hidden">
                                                    {item.title}
                                                </span>
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
            <SidebarFooter className="border-t border-gray-100 bg-white p-4 text-xs text-gray-400 group-data-[collapsible=icon]:hidden">
                <span>System v1.0</span>
            </SidebarFooter>
        </Sidebar>
    );
}
