import { useEffect, useState } from "react";
import { Head, usePage, router } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { motion } from "framer-motion";
import {
  Eye,
  EyeOff,
  FileText,
  Gauge,
  Loader2,
  Lock,
  LogIn,
  ShieldCheck,
  User,
} from "lucide-react";

import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { loginSchema } from "@/lib/FormSchema";

// Matches the sampled background of the reference card, so the logo and seal
// crops sit on it seamlessly.
const CARD_BG = "#f6f9fd";

const FEATURES = [
  { icon: FileText, label: "BIR-Compliant DAT Files" },
  { icon: ShieldCheck, label: "Secure and Reliable" },
  { icon: Gauge, label: "Faster Processing" },
];

const containerVariants = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: { duration: 0.5, staggerChildren: 0.1 } },
};

const itemVariants = {
  hidden: { opacity: 0, y: 16 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.45 } },
};

function Login() {
  const { errors: serverErrors = {} } = usePage().props;
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(loginSchema),
    defaultValues: { username: "", password: "", remember: false },
  });

  // Surface failed attempts from LoginController on the username field.
  useEffect(() => {
    Object.keys(serverErrors).forEach((key) => {
      setError(key, { message: serverErrors[key] });
    });
  }, [serverErrors, setError]);

  const onSubmit = (formData) => {
    setIsSubmitting(true);

    router.post("/login", formData, {
      onFinish: () => setIsSubmitting(false),
      onError: (err) => {
        Object.keys(err || {}).forEach((key) => {
          setError(key, { message: err[key] });
        });
      },
    });
  };

  return (
    <>
      <Head title="Login" />

      <div className="relative flex min-h-screen w-full flex-col overflow-hidden">
        {/* Fortress / BIR backdrop. JPEG, not the 2.3 MB source PNG -- visually
            identical here and it is the first paint of the whole app. */}
        <div
          className="absolute inset-0 bg-cover bg-center"
          style={{ backgroundImage: "url('/images/login-background.jpg')" }}
          aria-hidden="true"
        />
        {/* Deep blue wash: heavy on mobile for legibility, left-weighted on
            desktop so the tower and BIR facade still read through. */}
        <div
          className="absolute inset-0 bg-[#0a2452]/80 lg:bg-gradient-to-r lg:from-[#0a2452]/95 lg:via-[#0a2452]/45 lg:to-[#0a2452]/10"
          aria-hidden="true"
        />

        <motion.main
          className="relative z-10 mx-auto grid w-full max-w-7xl flex-1 items-center gap-10 px-5 py-12 lg:grid-cols-2 lg:gap-16 lg:px-10"
          initial="hidden"
          animate="visible"
          variants={containerVariants}
        >
          {/* Left: system identity */}
          <div className="space-y-7 text-white">
            <motion.div variants={itemVariants} className="border-l-4 border-sky-400 pl-5">
              <h1 className="text-3xl font-bold leading-tight tracking-tight sm:text-4xl lg:text-5xl">
                BIR DAT File
                <br className="hidden sm:block" /> Automation System
              </h1>
              <p className="mt-3 text-lg font-light text-slate-200 sm:text-xl lg:text-2xl">
                Accurate. Compliant. Automated.
              </p>
            </motion.div>

            <motion.p
              variants={itemVariants}
              className="max-w-md text-sm leading-relaxed text-slate-200 sm:text-base"
            >
              Streamline your BIR reporting process and generate submission-ready DAT
              files accurately and efficiently.
            </motion.p>

            <motion.ul
              variants={itemVariants}
              className="hidden max-w-sm space-y-4 rounded-xl border border-white/15 bg-[#0a2452]/60 p-5 backdrop-blur-sm sm:block"
            >
              {FEATURES.map(({ icon: Icon, label }) => (
                <li key={label} className="flex items-center gap-3.5">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/20 bg-white/10">
                    <Icon className="h-4 w-4 text-sky-200" />
                  </span>
                  <span className="text-sm font-medium text-slate-100">{label}</span>
                </li>
              ))}
            </motion.ul>
          </div>

          {/* Right: login card */}
          <motion.div
            variants={itemVariants}
            className="w-full max-w-md justify-self-center rounded-3xl p-7 shadow-2xl ring-1 ring-black/5 sm:p-9 lg:justify-self-end"
            style={{ backgroundColor: CARD_BG }}
          >
            <div className="flex justify-center">
              <img
                src="/images/fortress-steel-logo.png"
                alt="Fortress Steel Inc."
                className="h-12 w-auto sm:h-14"
              />
            </div>

            <div className="my-6 border-t border-slate-200" />

            <div className="text-center">
              <h2 className="text-2xl font-bold text-slate-900 sm:text-3xl">Welcome Back</h2>
              <p className="mt-1.5 text-sm text-slate-500">
                Sign in to access the Importation System
              </p>
            </div>

            {/* The seal is a visual reference only. Nothing here claims the BIR
                accredits, certifies, approves, or endorses this system -- it is an
                internal tool that prepares data for BIR reporting. */}
            <div className="mt-7 flex items-center gap-4">
              <img
                src="/images/bir-seal.png"
                alt="Bureau of Internal Revenue seal"
                className="h-20 w-20 shrink-0 object-contain"
              />
              <div className="border-l border-slate-200 pl-4">
                <p className="text-sm font-bold leading-snug text-slate-800">
                  BIR DAT File Automation
                </p>
                <p className="mt-1 text-xs text-slate-500">
                  Internal tool for BIR data preparation
                </p>
              </div>
            </div>

            <form onSubmit={handleSubmit(onSubmit)} className="mt-7 space-y-4">
              <div className="space-y-1.5">
                <label htmlFor="username" className="sr-only">
                  Username
                </label>
                <div className="relative">
                  <User className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="username"
                    type="text"
                    autoComplete="username"
                    placeholder="Username"
                    className={`h-12 bg-white pl-11 ${
                      errors.username ? "border-red-500 focus-visible:ring-red-500" : ""
                    }`}
                    {...register("username")}
                  />
                </div>
                {errors.username && (
                  <p className="text-xs font-medium text-red-500">{errors.username.message}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <label htmlFor="password" className="sr-only">
                  Password
                </label>
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="password"
                    type={showPassword ? "text" : "password"}
                    autoComplete="current-password"
                    placeholder="Password"
                    className={`h-12 bg-white pl-11 pr-11 ${
                      errors.password ? "border-red-500 focus-visible:ring-red-500" : ""
                    }`}
                    {...register("password")}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((visible) => !visible)}
                    aria-label={showPassword ? "Hide password" : "Show password"}
                    className="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition-colors hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0344a4]"
                  >
                    {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                  </button>
                </div>
                {errors.password && (
                  <p className="text-xs font-medium text-red-500">{errors.password.message}</p>
                )}
              </div>

              <label className="flex w-fit cursor-pointer items-center gap-2.5 text-sm text-slate-600">
                <input
                  type="checkbox"
                  {...register("remember")}
                  className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[#0344a4] focus:ring-[#0344a4]"
                />
                Remember me
              </label>

              <Button
                type="submit"
                disabled={isSubmitting}
                className="h-12 w-full bg-[#0344a4] text-base font-semibold text-white shadow-md transition-colors hover:bg-[#023384]"
              >
                {isSubmitting ? (
                  <Loader2 className="h-5 w-5 animate-spin" />
                ) : (
                  <span className="flex items-center justify-center gap-2">
                    <LogIn className="h-5 w-5" /> Login
                  </span>
                )}
              </Button>
            </form>

            <div className="mt-7 border-t border-slate-200 pt-4">
              <p className="flex items-center justify-center gap-1.5 text-xs text-slate-500">
                <Lock className="h-3.5 w-3.5" /> Your data is secure and encrypted
              </p>
            </div>
          </motion.div>
        </motion.main>

        <footer className="relative z-10 bg-[#0a2452] py-4 text-center text-xs text-slate-300 sm:text-sm">
          © 2026 Fortress Steel Inc. All Rights Reserved.
        </footer>
      </div>
    </>
  );
}

export default Login;
