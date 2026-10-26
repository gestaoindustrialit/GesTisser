using System;
using System.IO;
using System.Net;
using System.Text;
using System.Collections.Generic;
using System.Web.Script.Serialization;
using System.ServiceProcess;
using System.Threading;
using System.Drawing.Printing;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Diagnostics;

public sealed class GesTisserPrintAgent : ServiceBase {
    const string Version="0.1.0";
    static readonly JavaScriptSerializer Json=new JavaScriptSerializer { MaxJsonLength=1048576 };
    string root, url, agent, token, pdfExe; string[] allowed;
    Thread worker; volatile bool stopping;
    Dictionary<string,object> config;
    static string Text(Dictionary<string,object> d,string k) {return d.ContainsKey(k)?Convert.ToString(d[k]):"";}
    public GesTisserPrintAgent(){ServiceName="GesTisserPrintAgent";CanStop=true;AutoLog=true;}
    protected override void OnStart(string[] args){worker=new Thread(Run);worker.IsBackground=true;worker.Start();}
    protected override void OnStop(){stopping=true;if(worker!=null)worker.Join(15000);}
    void Log(string value){try{string path=Path.Combine(root,"agent-"+DateTime.UtcNow.ToString("yyyyMMdd")+".log");File.AppendAllText(path,DateTime.UtcNow.ToString("o")+" "+value+Environment.NewLine);}catch{}}
    byte[] Request(Dictionary<string,object> body){
        byte[] bytes=Encoding.UTF8.GetBytes(Json.Serialize(body));
        HttpWebRequest req=(HttpWebRequest)WebRequest.Create(url);req.Method="POST";req.ContentType="application/json";req.Timeout=30000;req.ReadWriteTimeout=30000;req.AllowAutoRedirect=false;
        req.Headers["Authorization"]="Bearer "+token;req.Headers["X-GesTisser-Agent"]=agent;req.ContentLength=bytes.Length;
        using(Stream stream=req.GetRequestStream())stream.Write(bytes,0,bytes.Length);
        using(HttpWebResponse response=(HttpWebResponse)req.GetResponse()){
            if(response.StatusCode!=HttpStatusCode.OK)throw new IOException("Resposta HTTP inesperada.");
            using(Stream stream=response.GetResponseStream())using(MemoryStream result=new MemoryStream()){
                byte[] buffer=new byte[8192];int count;while((count=stream.Read(buffer,0,buffer.Length))>0){if(result.Length+count>10485760)throw new IOException("Conteúdo demasiado grande.");result.Write(buffer,0,count);}return result.ToArray();
            }
        }
    }
    Dictionary<string,object> Api(Dictionary<string,object> body){return Json.Deserialize<Dictionary<string,object>>(Encoding.UTF8.GetString(Request(body)));}
    Dictionary<string,object> Body(string action,Dictionary<string,object> job){return new Dictionary<string,object>{{"action",action},{"id",Text(job,"id")},{"lease_token",Text(job,"lease_token")}};}
    void Report(Dictionary<string,object> job,string state,string error,string spool){var body=Body("report",job);body["state"]=state;body["error"]=error;body["spool_id"]=spool;Api(body);}
    void Save(string path,Dictionary<string,object> job,string state,string spool){
        job["local_state"]=state;job["spool_id"]=spool;
        string temp=path+".tmp";byte[] data=Encoding.UTF8.GetBytes(Json.Serialize(job));
        using(FileStream fs=new FileStream(temp,FileMode.Create,FileAccess.Write,FileShare.None)){fs.Write(data,0,data.Length);fs.Flush(true);}
        if(File.Exists(path))File.Replace(temp,path,null);else File.Move(temp,path);
    }
    void Recover(){foreach(string path in Directory.GetFiles(root,"job-*.json")){
        var job=Json.Deserialize<Dictionary<string,object>>(File.ReadAllText(path));string state=Text(job,"local_state");if(state=="done")continue;
        try{if(state=="accepted")Report(job,"spooler_accepted","",Text(job,"spool_id"));else Report(job,"unknown","Agente reiniciado; resultado físico não confirmado.",Text(job,"spool_id"));Save(path,job,"done",Text(job,"spool_id"));}
        catch{Log("Reconciliação pendente: "+Text(job,"id"));}
    }}
    void Heartbeat(){var printers=new List<object>();foreach(string name in PrinterSettings.InstalledPrinters){if(Array.IndexOf(allowed,name)<0)continue;var ps=new PrinterSettings();ps.PrinterName=name;printers.Add(new Dictionary<string,object>{{"name",name},{"online",ps.IsValid&&Native.IsOnline(name)}});}
        Api(new Dictionary<string,object>{{"action","heartbeat"},{"version",Version},{"printers",printers}});
    }
    void Print(Dictionary<string,object> job){
        string id=Text(job,"id");if(!System.Text.RegularExpressions.Regex.IsMatch(id,"\\A[a-f0-9]{32}\\z"))throw new IOException("ID inválido.");
        string path=Path.Combine(root,"job-"+id+".json");if(File.Exists(path)){Log("Trabalho já registado localmente: "+id);Report(job,"unknown","Diário local já contém este trabalho; impressão automática bloqueada.","");return;}
        string queue=Text(job,"queue_name");bool barrier=false;string spool="";
        try{
            if(Array.IndexOf(allowed,queue)<0)throw new IOException("Fila não autorizada localmente.");
            int copies=Convert.ToInt32(job["copies"]);if(copies<1||copies>100)throw new IOException("Número de cópias inválido.");
            string mime=Text(job,"mime");if(mime!="application/pdf"&&mime!="application/zpl")throw new IOException("Tipo inválido.");
            if(mime=="application/pdf"&&(!Path.IsPathRooted(pdfExe)||!File.Exists(pdfExe)))throw new IOException("SumatraPDF não configurado.");
            byte[] data=Request(Body("content",job));string hash;using(SHA256 sha=SHA256.Create())hash=BitConverter.ToString(sha.ComputeHash(data)).Replace("-","").ToLowerInvariant();if(hash!=Text(job,"content_hash"))throw new IOException("Hash inválido.");
            // Persist BEFORE requesting permission to touch spooler. Never print on ambiguous API result.
            Save(path,job,"uncertain","");barrier=true;Report(job,"processing","","");
            if(mime=="application/zpl")spool=Native.Raw(queue,data,copies,id).ToString();
            else{
                string pdf=Path.Combine(root,id+".pdf");File.WriteAllBytes(pdf,data);
                try{string settings=copies+"x,"+(Text(job,"orientation")=="landscape"?"landscape":"portrait")+",noscale";
                    ProcessStartInfo info=new ProcessStartInfo(pdfExe,"-silent -print-to "+Quote(queue)+" -print-settings "+Quote(settings)+" "+Quote(pdf));info.UseShellExecute=false;info.CreateNoWindow=true;
                    using(Process p=Process.Start(info)){if(!p.WaitForExit(120000)){try{p.Kill();}catch{}throw new IOException("Tempo PDF excedido; resultado desconhecido.");}if(p.ExitCode!=0)throw new IOException("Impressão PDF não confirmada.");}
                }finally{try{File.Delete(pdf);}catch{}}
                spool="pdf-helper";
            }
            Save(path,job,"accepted",spool);Report(job,"spooler_accepted","",spool);Save(path,job,"done",spool);Log("Aceite pelo spooler: "+id+" "+spool);
        }catch(Exception ex){Log("Falha no trabalho "+id+": "+ex.GetType().Name);if(Text(job,"local_state")=="accepted")return;try{Report(job,barrier?"unknown":"error",barrier?"Resultado desconhecido; não reenviar automaticamente.":ex.Message,spool);if(barrier)Save(path,job,"done",spool);}catch{}}
    }
    static string Quote(string value){if(value.IndexOf('"')>=0||value.IndexOf('\n')>=0||value.IndexOf('\r')>=0)throw new IOException("Argumento inválido.");return "\""+value+"\"";}
    void Run(){
        root=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),"GesTisserPrintAgent");
        try{config=Json.Deserialize<Dictionary<string,object>>(File.ReadAllText(Path.Combine(root,"config.json")));url=Text(config,"url");Uri uri=new Uri(url);if(uri.Scheme!="https"||!String.IsNullOrEmpty(uri.UserInfo)||!String.IsNullOrEmpty(uri.Query))throw new IOException("URL HTTPS inválido.");agent=Text(config,"agent_id");pdfExe=Text(config,"pdf_exe");
            token=Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(Text(config,"token_protected")),null,DataProtectionScope.LocalMachine));
            var list=new List<string>();foreach(object item in (System.Collections.IEnumerable)config["queues"])list.Add(Convert.ToString(item));allowed=list.ToArray();
            ServicePointManager.SecurityProtocol=SecurityProtocolType.Tls12;
        }catch(Exception e){Log("Configuração inválida: "+e.GetType().Name);return;}
        DateTime heartbeat=DateTime.MinValue;
        while(!stopping){try{
            Recover();if(DateTime.UtcNow>heartbeat){Heartbeat();heartbeat=DateTime.UtcNow.AddSeconds(30);}
            var response=Api(new Dictionary<string,object>{{"action","claim"}});if(response.ContainsKey("job")&&response["job"]!=null)Print((Dictionary<string,object>)response["job"]);
            foreach(string pdf in Directory.GetFiles(root,"*.pdf"))if(File.GetLastWriteTimeUtc(pdf)<DateTime.UtcNow.AddDays(-1)){try{File.Delete(pdf);}catch{}}
            foreach(string log in Directory.GetFiles(root,"agent-*.log"))if(File.GetLastWriteTimeUtc(log)<DateTime.UtcNow.AddDays(-30))File.Delete(log);
            // Job journals intentionally retained: deleting them can weaken duplicate protection.
        }catch(Exception ex){Log("Comunicação/execução interrompida: "+ex.GetType().Name);}
        for(int i=0;i<50&&!stopping;i++)Thread.Sleep(100);}
    }
    static void SelfTest(){
        var parsed=Json.Deserialize<Dictionary<string,object>>("{\"queues\":[\"Zebra\",\"HP\"]}");int count=0;foreach(object item in (System.Collections.IEnumerable)parsed["queues"])count++;
        if(count!=2)throw new Exception("Queue configuration failed.");
        byte[] secret=Encoding.UTF8.GetBytes("synthetic-self-test");byte[] protectedValue=ProtectedData.Protect(secret,null,DataProtectionScope.LocalMachine);
        if(Encoding.UTF8.GetString(ProtectedData.Unprotect(protectedValue,null,DataProtectionScope.LocalMachine))!="synthetic-self-test")throw new Exception("DPAPI roundtrip failed.");
        bool rejected=false;try{Quote("bad\"queue");}catch(IOException){rejected=true;}if(!rejected)throw new Exception("Argument validation failed.");
        string folder=Path.Combine(Path.GetTempPath(),"gestisser-self-test-"+Guid.NewGuid().ToString("N"));Directory.CreateDirectory(folder);
        try{var service=new GesTisserPrintAgent();var job=new Dictionary<string,object>{{"id","fixture"}};string path=Path.Combine(folder,"job.json");service.Save(path,job,"uncertain","");service.Save(path,job,"accepted","42");var restored=Json.Deserialize<Dictionary<string,object>>(File.ReadAllText(path));if(Text(restored,"local_state")!="accepted"||Text(restored,"spool_id")!="42")throw new Exception("Durable journal failed.");}
        finally{Directory.Delete(folder,true);}
        Console.WriteLine("PASS: queue configuration, DPAPI, argument validation and durable journal. No print requests sent.");
    }
    public static void Main(string[] args){if(args.Length==1&&args[0]=="--self-test"){SelfTest();return;}if(args.Length==1&&args[0]=="--console"){var service=new GesTisserPrintAgent();service.Run();}else ServiceBase.Run(new GesTisserPrintAgent());}
}
static class Native {
    [StructLayout(LayoutKind.Sequential,CharSet=CharSet.Unicode)]struct DocInfo{[MarshalAs(UnmanagedType.LPWStr)]public string name;[MarshalAs(UnmanagedType.LPWStr)]public string file;[MarshalAs(UnmanagedType.LPWStr)]public string type;}
    [DllImport("winspool.drv",EntryPoint="OpenPrinterW",SetLastError=true,CharSet=CharSet.Unicode)]static extern bool OpenPrinter(string name,out IntPtr handle,IntPtr defaults);
    [DllImport("winspool.drv",SetLastError=true)]static extern bool ClosePrinter(IntPtr handle);
    [DllImport("winspool.drv",EntryPoint="StartDocPrinterW",SetLastError=true,CharSet=CharSet.Unicode)]static extern int StartDocPrinter(IntPtr handle,int level,ref DocInfo info);
    [DllImport("winspool.drv",SetLastError=true)]static extern bool EndDocPrinter(IntPtr handle);
    [DllImport("winspool.drv",SetLastError=true)]static extern bool WritePrinter(IntPtr handle,byte[] bytes,int count,out int written);
    [DllImport("winspool.drv",EntryPoint="GetPrinterW",SetLastError=true)]static extern bool GetPrinter(IntPtr handle,int level,IntPtr buffer,int size,out int needed);
    public static bool IsOnline(string name){IntPtr h;if(!OpenPrinter(name,out h,IntPtr.Zero))return false;try{IntPtr b=Marshal.AllocHGlobal(4);try{int needed;if(!GetPrinter(h,6,b,4,out needed))return false;int status=Marshal.ReadInt32(b);return (status & (0x2|0x8|0x10|0x20|0x80|0x1000|0x400000))==0;}finally{Marshal.FreeHGlobal(b);}}finally{ClosePrinter(h);}}
    public static int Raw(string queue,byte[] bytes,int copies,string id){IntPtr h;if(!OpenPrinter(queue,out h,IntPtr.Zero))throw new IOException("Não foi possível abrir a fila.");try{DocInfo doc=new DocInfo{name="GesTisser "+id,type="RAW"};int spool=StartDocPrinter(h,1,ref doc);if(spool==0)throw new IOException("StartDocPrinter falhou.");try{for(int i=0;i<copies;i++){int written;if(!WritePrinter(h,bytes,bytes.Length,out written)||written!=bytes.Length)throw new IOException("Envio incompleto ao spooler.");}}finally{if(!EndDocPrinter(h))throw new IOException("EndDocPrinter falhou.");}return spool;}finally{ClosePrinter(h);}}
}
